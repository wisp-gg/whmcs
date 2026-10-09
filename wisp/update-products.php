<?php

/**
 * WISP product update endpoint for the panel's "Export to WHMCS".
 *
 * Clears package-managed options on products already linked to a package, so
 * they inherit from it. Whatever the panel sends, this endpoint only ever:
 *  - writes an empty value, and only to WISP_EXPORT_CLEARABLE_KEYS columns;
 *  - touches `wisp` products whose identifier still matches the request;
 *  - writes when every value still equals what the panel saw (else nothing);
 *  - records each overwritten value in mod_wisp_product_changes so the batch
 *    can be undone from that table (undo never takes values from the panel).
 *
 * Modes: dry_run, apply, undo_dry_run, undo, history. Dry runs run the same
 * checks inside a transaction that is always rolled back.
 */

use Illuminate\Database\Capsule\Manager as Capsule;

require_once __DIR__ . '/../../../init.php';

require_once __DIR__ . '/panel-auth.php';

const WISP_EXPORT_SCOPE = 'whmcs-products-write';

const WISP_EXPORT_CHANGES_TABLE = 'mod_wisp_product_changes';

class WispExportRejected extends Exception
{
    public int $status;

    public array $conflicts;

    public function __construct(int $status, string $message, array $conflicts = [])
    {
        parent::__construct($message);
        $this->status = $status;
        $this->conflicts = $conflicts;
    }
}

function wisp_export_ensure_changes_table(): void
{
    if (Capsule::schema()->hasTable(WISP_EXPORT_CHANGES_TABLE)) {
        return;
    }

    Capsule::schema()->create(WISP_EXPORT_CHANGES_TABLE, function ($table) {
        $table->increments('id');
        $table->string('batch_id', 32)->index();
        $table->unsignedInteger('pid');
        $table->string('option_key', 64);
        $table->string('column_name', 32);
        $table->text('before_value');
        $table->text('after_value');
        $table->string('actor', 191)->default('');
        $table->timestamp('created_at')->nullable();
        $table->timestamp('undone_at')->nullable();
    });
}

/**
 * Lock and check every requested product, returning what would be written. Any
 * product that moved since the panel read it fails the whole batch.
 *
 * @return list<array{pid: int, name: string, fields: list<array{key: string, column: string, before: string, after: string}>}>
 *
 * @throws WispExportRejected
 */
function wisp_export_plan_clear(array $changes): array
{
    $columns = wisp_export_clearable_columns();
    $templateColumn = wisp_export_template_column();

    if ($templateColumn === null) {
        throw new WispExportRejected(500, 'WISP server module is outdated: it is missing the server_template_id option.');
    }

    if ($changes === []) {
        throw new WispExportRejected(422, 'No changes were sent.');
    }

    $planned = [];
    $conflicts = [];
    $seen = [];

    foreach ($changes as $change) {
        $pid = (int) ($change['pid'] ?? 0);
        $identifier = (string) ($change['identifier'] ?? '');
        $fields = $change['fields'] ?? null;

        if ($pid <= 0 || $identifier === '' || ! is_array($fields) || $fields === []) {
            throw new WispExportRejected(422, 'Each change needs a product id, its package identifier and at least one field.');
        }

        if (isset($seen[$pid])) {
            throw new WispExportRejected(422, "Product #{$pid} was sent more than once.");
        }
        $seen[$pid] = true;

        foreach ($fields as $key => $from) {
            if (! isset($columns[$key])) {
                throw new WispExportRejected(422, "The '{$key}' option cannot be cleared.");
            }

            if (! is_string($from) || $from === '') {
                throw new WispExportRejected(422, "Product #{$pid}: the current value of '{$key}' is missing.");
            }
        }

        $product = Capsule::table('tblproducts')->where('id', $pid)->lockForUpdate()->first();

        if ($product === null) {
            $conflicts[] = ['pid' => $pid, 'reason' => 'missing'];

            continue;
        }

        if ($product->servertype !== 'wisp') {
            $conflicts[] = ['pid' => $pid, 'reason' => 'not_wisp'];

            continue;
        }

        $currentIdentifier = (string) ($product->{$templateColumn} ?? '');
        if ($currentIdentifier === '' || $currentIdentifier !== $identifier) {
            $conflicts[] = ['pid' => $pid, 'reason' => 'identifier_changed', 'expected' => $identifier, 'actual' => $currentIdentifier];

            continue;
        }

        $planFields = [];
        foreach ($fields as $key => $from) {
            $column = $columns[$key];
            $actual = (string) ($product->{$column} ?? '');

            if ($actual !== $from) {
                $conflicts[] = ['pid' => $pid, 'key' => $key, 'reason' => 'value_changed', 'expected' => $from, 'actual' => $actual];

                continue;
            }

            $planFields[] = ['key' => $key, 'column' => $column, 'before' => $actual, 'after' => ''];
        }

        $planned[] = ['pid' => $pid, 'name' => (string) $product->name, 'fields' => $planFields];
    }

    if ($conflicts !== []) {
        throw new WispExportRejected(409, 'Some products changed in WHMCS since they were loaded. Nothing was changed.', $conflicts);
    }

    return $planned;
}

/**
 * @return list<array{pid: int, name: string, fields: list<array{key: string, column: string, before: string, after: string}>}>
 *
 * @throws WispExportRejected
 */
function wisp_export_plan_undo(string $batchId): array
{
    if ($batchId === '' || ! Capsule::schema()->hasTable(WISP_EXPORT_CHANGES_TABLE)) {
        throw new WispExportRejected(404, 'That export was not found.');
    }

    $rows = Capsule::table(WISP_EXPORT_CHANGES_TABLE)->where('batch_id', $batchId)->orderBy('id')->get();
    if ($rows->isEmpty()) {
        throw new WispExportRejected(404, 'That export was not found.');
    }

    if ($rows->contains(fn ($row) => $row->undone_at !== null)) {
        throw new WispExportRejected(409, 'That export was already undone.');
    }

    $columns = wisp_export_clearable_columns();
    $planned = [];
    $conflicts = [];

    foreach ($rows->groupBy('pid') as $pid => $productRows) {
        $product = Capsule::table('tblproducts')->where('id', (int) $pid)->lockForUpdate()->first();

        if ($product === null) {
            $conflicts[] = ['pid' => (int) $pid, 'reason' => 'missing'];

            continue;
        }

        $planFields = [];
        foreach ($productRows as $row) {
            // A module update that reordered the options would point the stored
            // column at a different setting; refuse rather than restore into it.
            if (($columns[$row->option_key] ?? null) !== $row->column_name) {
                $conflicts[] = ['pid' => (int) $pid, 'key' => $row->option_key, 'reason' => 'module_layout_changed'];

                continue;
            }

            $actual = (string) ($product->{$row->column_name} ?? '');
            if ($actual !== (string) $row->after_value) {
                $conflicts[] = ['pid' => (int) $pid, 'key' => $row->option_key, 'reason' => 'value_changed', 'expected' => (string) $row->after_value, 'actual' => $actual];

                continue;
            }

            $planFields[] = ['key' => $row->option_key, 'column' => $row->column_name, 'before' => $actual, 'after' => (string) $row->before_value];
        }

        $planned[] = ['pid' => (int) $pid, 'name' => (string) $product->name, 'fields' => $planFields];
    }

    if ($conflicts !== []) {
        throw new WispExportRejected(409, 'Some products changed in WHMCS since this export. Nothing was changed.', $conflicts);
    }

    return $planned;
}

function wisp_export_write(array $planned): void
{
    foreach ($planned as $product) {
        $update = [];
        foreach ($product['fields'] as $field) {
            $update[$field['column']] = $field['after'];
        }

        Capsule::table('tblproducts')->where('id', $product['pid'])->update($update);
    }
}

/**
 * Read the written columns back after commit so the panel is told about a write
 * that did not land rather than assuming it did.
 */
function wisp_export_verify_written(array $planned): bool
{
    try {
        foreach ($planned as $product) {
            $row = Capsule::table('tblproducts')->where('id', $product['pid'])->first();
            foreach ($product['fields'] as $field) {
                if ($row === null || (string) ($row->{$field['column']} ?? '') !== $field['after']) {
                    return false;
                }
            }
        }
    } catch (\Throwable $e) {
        return false;
    }

    return true;
}

/**
 * The write has already committed by the time this runs, so a logging failure
 * must not turn the response into an error the panel would read as "not saved".
 */
function wisp_export_log(string $message): void
{
    try {
        logActivity($message);
    } catch (\Throwable $e) {
    }
}

function wisp_export_count_fields(array $planned): int
{
    return array_sum(array_map(fn ($product) => count($product['fields']), $planned));
}

/**
 * @param  callable(): array  $plan
 * @param  callable(array): mixed  $write
 * @return array{planned: array, written: mixed}
 */
function wisp_export_in_transaction(callable $plan, ?callable $write): array
{
    $connection = Capsule::connection();
    $connection->beginTransaction();

    try {
        $planned = $plan();

        if ($write === null) {
            $connection->rollBack();

            return ['planned' => $planned, 'written' => null];
        }

        $written = $write($planned);
        $connection->commit();

        return ['planned' => $planned, 'written' => $written];
    } catch (\Throwable $e) {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        throw $e;
    }
}

function wisp_export_history(): array
{
    if (! Capsule::schema()->hasTable(WISP_EXPORT_CHANGES_TABLE)) {
        return [];
    }

    return Capsule::table(WISP_EXPORT_CHANGES_TABLE)
        ->select([
            'batch_id',
            Capsule::raw('MIN(id) as first_id'),
            Capsule::raw('MAX(actor) as actor'),
            Capsule::raw('MIN(created_at) as created_at'),
            Capsule::raw('MAX(undone_at) as undone_at'),
            Capsule::raw('COUNT(DISTINCT pid) as products'),
            Capsule::raw('COUNT(*) as fields'),
        ])
        ->groupBy('batch_id')
        ->orderBy('first_id', 'desc')
        ->limit(50)
        ->get()
        ->map(fn ($row) => [
            'batch_id' => (string) $row->batch_id,
            'actor' => (string) $row->actor,
            'created_at' => $row->created_at,
            'undone_at' => $row->undone_at,
            'products' => (int) $row->products,
            'fields' => (int) $row->fields,
        ])
        ->all();
}

try {
    $jwt = isset($_POST['jwt']) ? (string) $_POST['jwt'] : '';
    $mode = isset($_POST['mode']) ? (string) $_POST['mode'] : '';
    $rawPayload = isset($_POST['payload']) ? (string) $_POST['payload'] : '';

    if ($jwt === '') {
        wisp_export_respond(401, ['error' => 'Missing token.']);
    }

    $claims = wisp_export_verify_jwt($jwt);
    if ($claims === null) {
        wisp_export_respond(403, ['error' => 'This request was not signed by a recognised WISP panel.']);
    }

    // An import token carries no scope, so it can never authorise a write. The
    // hash binds the token to this exact mode + payload.
    if (($claims['scope'] ?? null) !== WISP_EXPORT_SCOPE
        || ! is_string($claims['payload_sha256'] ?? null)
        || ! hash_equals($claims['payload_sha256'], hash('sha256', $mode . "\n" . $rawPayload))) {
        wisp_export_respond(403, ['error' => 'This request does not match what the panel signed.']);
    }

    if (! wisp_export_relay_jwt($jwt)) {
        wisp_export_respond(403, ['error' => 'The panel did not recognise this request, or there are insufficient permissions on the application API key.']);
    }

    // Base64 so WHMCS's input escaping cannot rewrite the JSON or the values in it.
    $payload = json_decode((string) base64_decode($rawPayload, true), true);
    if (! is_array($payload)) {
        wisp_export_respond(422, ['error' => 'The request payload is not valid.']);
    }

    $actor = substr((string) ($claims['actor'] ?? ''), 0, 191);

    switch ($mode) {
        case 'history':
            wisp_export_respond(200, ['batches' => wisp_export_history()]);

        case 'dry_run':
        case 'apply':
            $changes = $payload['changes'] ?? null;
            if (! is_array($changes)) {
                wisp_export_respond(422, ['error' => 'The request payload is not valid.']);
            }

            if ($mode === 'dry_run') {
                $result = wisp_export_in_transaction(fn () => wisp_export_plan_clear($changes), null);
                wisp_export_respond(200, ['ok' => true, 'products' => $result['planned']]);
            }

            // DDL commits implicitly, so the backup table must exist before the
            // write transaction opens.
            wisp_export_ensure_changes_table();

            $result = wisp_export_in_transaction(fn () => wisp_export_plan_clear($changes), function (array $planned) use ($actor) {
                $batchId = bin2hex(random_bytes(16));
                $now = date('Y-m-d H:i:s');

                foreach ($planned as $product) {
                    foreach ($product['fields'] as $field) {
                        Capsule::table(WISP_EXPORT_CHANGES_TABLE)->insert([
                            'batch_id' => $batchId,
                            'pid' => $product['pid'],
                            'option_key' => $field['key'],
                            'column_name' => $field['column'],
                            'before_value' => $field['before'],
                            'after_value' => $field['after'],
                            'actor' => $actor,
                            'created_at' => $now,
                        ]);
                    }
                }

                wisp_export_write($planned);

                return $batchId;
            });

            foreach ($result['planned'] as $product) {
                wisp_export_log(sprintf(
                    'WISP Export to WHMCS: cleared %s on product #%d (%s) so it inherits from its package. Batch %s by %s.',
                    implode(', ', array_column($product['fields'], 'key')),
                    $product['pid'],
                    $product['name'],
                    $result['written'],
                    $actor
                ));
            }

            wisp_export_respond(200, [
                'ok' => true,
                'batch_id' => $result['written'],
                'verified' => wisp_export_verify_written($result['planned']),
                'products' => $result['planned'],
            ]);

        case 'undo_dry_run':
        case 'undo':
            $batchId = (string) ($payload['batch_id'] ?? '');

            if ($mode === 'undo_dry_run') {
                $result = wisp_export_in_transaction(fn () => wisp_export_plan_undo($batchId), null);
                wisp_export_respond(200, ['ok' => true, 'products' => $result['planned']]);
            }

            $result = wisp_export_in_transaction(fn () => wisp_export_plan_undo($batchId), function (array $planned) use ($batchId) {
                wisp_export_write($planned);

                Capsule::table(WISP_EXPORT_CHANGES_TABLE)
                    ->where('batch_id', $batchId)
                    ->update(['undone_at' => date('Y-m-d H:i:s')]);

                return $batchId;
            });

            wisp_export_log(sprintf(
                'WISP Export to WHMCS: undid batch %s (%d field(s) on %d product(s)) by %s.',
                $batchId,
                wisp_export_count_fields($result['planned']),
                count($result['planned']),
                $actor
            ));

            wisp_export_respond(200, [
                'ok' => true,
                'batch_id' => $batchId,
                'verified' => wisp_export_verify_written($result['planned']),
                'products' => $result['planned'],
            ]);

        default:
            wisp_export_respond(422, ['error' => 'Unknown mode.']);
    }
} catch (WispExportRejected $e) {
    wisp_export_respond($e->status, ['ok' => false, 'error' => $e->getMessage(), 'conflicts' => $e->conflicts]);
} catch (\Throwable $e) {
    wisp_export_respond(500, ['ok' => false, 'error' => 'Update failed: ' . $e->getMessage()]);
}
