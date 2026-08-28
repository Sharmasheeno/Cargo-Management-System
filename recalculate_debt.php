<?php
/**
 * recalculate_debt.php
 *
 * Recalculates customers.debt_amount from transactional truth:
 *
 *   debt_amount = SUM(invoices.total_amount - invoices.paid_amount)
 *                 for valid (non-cancelled/void) invoices
 *
 * DRY-RUN DIAGNOSTIC. Use --apply only after human review of the dry-run.
 *
 * ---------------------------------------------------------------------------
 * WHEN --apply IS SAFE
 * ---------------------------------------------------------------------------
 * `--apply` may only be used when `invoices` is the complete authoritative
 * source of customer receivables — i.e. every invoice ever raised is still
 * present, and its `total_amount` / `paid_amount` reflect its final state.
 *
 * WHEN --apply IS UNSAFE
 * ---------------------------------------------------------------------------
 * If historical invoices were hard-deleted, or if paid_amount was corrupted
 * by the historical double-decrement defect and later manually adjusted,
 * `--apply` will overwrite `customers.debt_amount` with a value that does
 * not reflect real customer activity. Investigate first.
 *
 * SAFETY RULES:
 *   - STRICT tenant scoping: customer IDs are NOT globally unique, so every
 *     aggregation runs per-tenant. The old version grouped by customer_id
 *     across ALL tenants, corrupting every tenant after the first.
 *   - Only financially valid invoices count: status NOT IN
 *     ('cancelled','void'). Draft/unpaid/partial/paid all carry AR.
 *   - Payment truth comes from `invoices.paid_amount`, NOT from the
 *     `receipts` table. Historical receipt rows have been hard-deleted in
 *     this DB (see AUTO_INCREMENT vs row count), so `SUM(receipts.amount)`
 *     understates paid amounts and would double-count debt on payoff.
 *     `invoices.paid_amount` is the surviving truth and is what the current
 *     add_payment path also maintains.
 *   - Dry-run by default; --apply is opt-in and destructive.
 *   - NEVER run --apply blindly against production history; review the
 *     dry-run first. This script MUST NOT be scheduled or automated.
 *
 * Canonical rule (documented in the finance acceptance):
 *   customers.debt_amount is a DERIVED CACHE of invoice/receipt truth and
 *   must never be treated as an independent source of financial truth.
 */

require_once __DIR__ . '/config/db_connect.php';

$apply = in_array('--apply', $argv ?? [], true);
echo "Recalculating customer debt (per-tenant, invoice-vs-receipt truth)\n";
echo $apply ? "MODE: APPLY (--apply)\n" : "MODE: DRY RUN (pass --apply to write)\n";
echo "Rule: debt = SUM(invoices.total_amount - invoices.paid_amount)\n"
   . "         for status NOT IN ('cancelled','void'), per tenant\n\n";

try {
    // Per-tenant aggregation, joined through the tenant to guarantee scoping.
    // Both invoiced and paid come from the same authoritative row.
    $rows = $pdo->query("
        SELECT c.id            AS customer_id,
               c.tenant_id,
               c.customer_name,
               COALESCE(c.debt_amount, 0)                                        AS stored_debt,
               COALESCE(i.invoiced, 0)                                           AS invoiced,
               COALESCE(i.paid, 0)                                               AS paid,
               (COALESCE(i.invoiced, 0) - COALESCE(i.paid, 0))                   AS calculated_debt,
               (COALESCE(c.debt_amount, 0) - (COALESCE(i.invoiced, 0) - COALESCE(i.paid, 0))) AS difference
        FROM customers c
        LEFT JOIN (
            SELECT tenant_id, customer_id,
                   SUM(total_amount) AS invoiced,
                   SUM(paid_amount)  AS paid
            FROM invoices
            WHERE status NOT IN ('cancelled','void')
            GROUP BY tenant_id, customer_id
        ) i ON i.tenant_id = c.tenant_id AND i.customer_id = c.id
        ORDER BY c.tenant_id, c.id
    ")->fetchAll(PDO::FETCH_ASSOC);

    $mismatches = 0;
    $update = $pdo->prepare("UPDATE customers SET debt_amount = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?");

    foreach ($rows as $row) {
        $diff = round((float)$row['difference'], 2);
        if (abs($diff) > 0.005) {
            $mismatches++;
            echo sprintf(
                "[MISMATCH] tenant=%d customer=%d (%s): stored=%.2f invoiced=%.2f paid=%.2f calculated=%.2f diff=%.2f\n",
                $row['tenant_id'], $row['customer_id'], $row['customer_name'],
                $row['stored_debt'], $row['invoiced'], $row['paid'],
                $row['calculated_debt'], $diff
            );
        }
        if ($apply) {
            $update->execute([$row['calculated_debt'], $row['customer_id'], $row['tenant_id']]);
        }
    }

    echo "\nCustomers scanned: " . count($rows) . ", mismatches: $mismatches\n";
    echo $apply ? "Done — debt_amount synchronized.\n"
                : "Dry run complete — no data was modified.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
