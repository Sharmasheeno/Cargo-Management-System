<?php
/**
 * AccountingService.php
 * Core engine for Double-Entry Bookkeeping infaras cargo
 */

class AccountingService {
    private $pdo;
    private $tenant_id;
    private $user_id;

    public function __construct($pdo, $tenant_id, $user_id) {
        $this->pdo = $pdo;
        $this->tenant_id = $tenant_id;
        $this->user_id = $user_id;
    }

    /**
     * Post a double-entry transaction to the journal
     */
    public function postToLedger($entry_number, $date, $description, $lines, $ref_type = null, $ref_id = null) {
        $startedTransaction = false;
        try {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                $startedTransaction = true;
            }

            foreach ($lines as $line) {
                // 1. Insert Journal Entry
                $stmt = $this->pdo->prepare("INSERT INTO journal_entries 
                    (tenant_id, entry_number, entry_date, account_name, account_code, debit, credit, description, reference_type, reference_id, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                $stmt->execute([
                    $this->tenant_id,
                    $entry_number,
                    $date,
                    $line['account_name'],
                    $line['account_code'],
                    $line['debit'],
                    $line['credit'],
                    $description,
                    $ref_type,
                    $ref_id,
                    $this->user_id
                ]);

                // 2. Update Chart of Accounts balance
                // Debit increases Assets/Expenses, decreases Liabilities/Equity/Revenue
                // Credit increases Liabilities/Equity/Revenue, decreases Assets/Expenses
                // Scoped to THIS tenant's own CoA row so postings never bleed
                // into the tenant-agnostic (tenant_id IS NULL) seed rows.
                $stmtAcc = $this->pdo->prepare("SELECT id, account_type FROM chart_of_accounts WHERE account_code = ? AND tenant_id = ?");
                $stmtAcc->execute([$line['account_code'], $this->tenant_id]);
                $acc = $stmtAcc->fetch();

                if ($acc) {
                    $adjustment = 0;
                    if (in_array($acc['account_type'], ['asset', 'expense'])) {
                        $adjustment = $line['debit'] - $line['credit'];
                    } else {
                        $adjustment = $line['credit'] - $line['debit'];
                    }

                    $updateAcc = $this->pdo->prepare("UPDATE chart_of_accounts SET balance = balance + ? WHERE id = ?");
                    $updateAcc->execute([$adjustment, $acc['id']]);
                }
            }

            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
            return true;
        } catch (Exception $e) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("Accounting Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Auto-Journalize an Invoice (Revenue Recognition)
     */
    public function journalizeInvoice($invoice_id) {
        $stmt = $this->pdo->prepare("SELECT * FROM invoices WHERE id = ?");
        $stmt->execute([$invoice_id]);
        $inv = $stmt->fetch();

        if (!$inv) return false;

        $lines = [
            // Debit Accounts Receivable (1100)
            ['account_name' => 'Accounts Receivable', 'account_code' => '1100', 'debit' => $inv['total_amount'], 'credit' => 0]
        ];

        if ($inv['tax'] > 0) {
            // Credit Tax Payable (2100)
            $lines[] = ['account_name' => 'Sales Tax Payable', 'account_code' => '2100', 'debit' => 0, 'credit' => $inv['tax']];
            // Credit Sales Revenue (4000) - Only the subtotal
            $lines[] = ['account_name' => 'Sales Revenue', 'account_code' => '4000', 'debit' => 0, 'credit' => $inv['subtotal']];
        } else {
            // Credit Sales Revenue (4000) - Full amount
            $lines[] = ['account_name' => 'Sales Revenue', 'account_code' => '4000', 'debit' => 0, 'credit' => $inv['total_amount']];
        }

        return $this->postToLedger($inv['invoice_number'], $inv['invoice_date'], "Invoice: " . $inv['invoice_number'], $lines, 'invoice', $inv['id']);
    }

    /**
     * Auto-Journalize a Receipt (Cash Collection)
     */
    public function journalizeReceipt($receipt_id) {
        $stmt = $this->pdo->prepare("SELECT r.*, ba.account_name as bank_name FROM receipts r LEFT JOIN bank_accounts ba ON r.bank_account_id = ba.id WHERE r.id = ?");
        $stmt->execute([$receipt_id]);
        $rec = $stmt->fetch();

        if (!$rec) return false;

        $lines = [
            // Debit Bank/Cash (1010)
            ['account_name' => $rec['bank_name'] ?: 'Cash on Hand', 'account_code' => '1010', 'debit' => $rec['amount'], 'credit' => 0],
            // Credit Accounts Receivable (1100)
            ['account_name' => 'Accounts Receivable', 'account_code' => '1100', 'debit' => 0, 'credit' => $rec['amount']]
        ];

        return $this->postToLedger($rec['receipt_number'], $rec['payment_date'], "Receipt: " . $rec['receipt_number'], $lines, 'receipt', $rec['id']);
    }

    /**
     * Auto-Journalize a Vendor Bill (Liability Recognition)
     */
    public function journalizeVendorBill($bill_id) {
        $stmt = $this->pdo->prepare("SELECT * FROM vendor_bills WHERE id = ?");
        $stmt->execute([$bill_id]);
        $bill = $stmt->fetch();

        if (!$bill) return false;

        // Vendor_bills schema stores total in `total_amount` and has no
        // per-bill category column (categorization lives on line items /
        // vendor). Prior code read `amount` / `category` — both absent —
        // which posted NULL debit/credit lines. Use the correct fields
        // and default the account name to "Cost of Sales".
        $amount   = (float)($bill['total_amount'] ?? 0);
        $category = $bill['category'] ?? null;
        $lines = [
            // Debit Expense (5000)
            ['account_name' => $category ?: 'Cost of Sales', 'account_code' => '5000', 'debit' => $amount, 'credit' => 0],
            // Credit Accounts Payable (2000)
            ['account_name' => 'Accounts Payable', 'account_code' => '2000', 'debit' => 0, 'credit' => $amount]
        ];

        return $this->postToLedger($bill['bill_number'], $bill['bill_date'], "Vendor Bill: " . $bill['bill_number'], $lines, 'vendor_bill', $bill['id']);
    }

    /**
     * Auto-Journalize a Payment (Money Out / Expense)
     */
    public function journalizePayment($payment_id) {
        $stmt = $this->pdo->prepare("SELECT p.*, ba.account_name as bank_name FROM payments p LEFT JOIN bank_accounts ba ON p.bank_account_id = ba.id WHERE p.id = ?");
        $stmt->execute([$payment_id]);
        $pay = $stmt->fetch();

        if (!$pay) return false;

        $lines = [];
        
        if ($pay['vendor_bill_id']) {
            // Payment against a Bill
            // Debit Accounts Payable (2000)
            $lines[] = ['account_name' => 'Accounts Payable', 'account_code' => '2000', 'debit' => $pay['amount'], 'credit' => 0];
        } else {
            // Direct Expense (Voucher)
            // Debit Expense (5000)
            $lines[] = ['account_name' => $pay['category'] ?: 'General Expense', 'account_code' => '5000', 'debit' => $pay['amount'], 'credit' => 0];
        }

        // Credit Bank/Cash (1010)
        $lines[] = ['account_name' => $pay['bank_name'] ?: 'Cash on Hand', 'account_code' => '1010', 'debit' => 0, 'credit' => $pay['amount']];

        return $this->postToLedger($pay['payment_number'], $pay['payment_date'], "Payment: " . $pay['payment_number'], $lines, 'payment', $pay['id']);
    }
}
