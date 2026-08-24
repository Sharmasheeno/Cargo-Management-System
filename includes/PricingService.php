<?php
/**
 * PricingService.php
 * Handles automatic pricing based on CBM, Weight, and Route rates.
 */

class PricingService {
    private $pdo;
    private $tenant_id;

    public function __construct($pdo, $tenant_id) {
        $this->pdo = $pdo;
        $this->tenant_id = $tenant_id;
    }

    /**
     * Calculate suggested price for a shipment/container
     */
    public function calculatePrice($cbm, $weight, $route_name) {
        $stmt = $this->pdo->prepare("SELECT * FROM pricing_rates 
            WHERE route_name = ? AND tenant_id = ? AND is_active = 1 
            LIMIT 1");
        $stmt->execute([$route_name, $this->tenant_id]);
        $rate = $stmt->fetch();

        if (!$rate) {
            // Default rates if none found
            return $cbm * 150; // Default $150 per CBM
        }

        $price_cbm = $cbm * $rate['rate_per_cbm'];
        $price_weight = $weight * $rate['rate_per_kg'];

        // Usually take the higher of the two (Volume vs Weight)
        $final_price = max($price_cbm, $price_weight);

        return max($final_price, $rate['min_charge']);
    }
}
