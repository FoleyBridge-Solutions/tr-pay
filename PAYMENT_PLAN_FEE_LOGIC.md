# Payment Plan Fee Logic

This document outlines the logic used for calculating fees on Payment Plans in the `PaymentFlow` Livewire component.

## Fee Structure: Risk-Based Algorithm

The payment plan fee is calculated dynamically based on a **"Risk Factor" Matrix** that considers both the **Plan Duration** and the **Down Payment Percentage**. This structure encourages larger down payments and shorter terms.

### 1. Base Setup Fee (Fixed)
- **Cost:** `$15.00` (Fixed administrative fee)

### 2. Variable Rate Matrix
A percentage fee is applied to the **Principal Financed** (Total Invoice Amount - Down Payment), NOT the total amount.

| Down Payment % ($D%) | Short Term (2-4 Mo) | Medium Term (5-8 Mo) | Long Term (9-12 Mo) |
| :--- | :---: | :---: | :---: |
| **High (> 30%)** | 2.0% | 3.5% | 5.0% |
| **Standard (15-30%)** | 3.0% | 5.0% | 7.0% |
| **Low (< 15%)** | 4.5% | 7.0% | 9.0% |

### The Formula

$$
\text{Total Fee} = \$15.00 + (\text{Principal Financed} \times \text{Rate From Matrix})
$$

Where:
*   **Principal Financed** = Total Payment Amount - Down Payment

### 3. Credit Card Fee Interaction
If the customer chooses to pay via Credit Card:
- The standard **3% Credit Card Processing Fee** applies.
- This 3% is calculated on the **TOTAL** amount (Principal + Payment Plan Fee).

### Example Calculations

**Assumptions:** Invoice Total: $1,000.00

#### Scenario A: The "Ideal" Client (Low Risk)
*   **Plan:** 3 Months (Short)
*   **Down Payment:** $400 (40% - High)
*   **Principal Financed:** $600
*   **Rate:** 2.0%
*   **Fee Calculation:** $15 + ($600 × 0.02) = **$27.00**

#### Scenario B: The "Standard" Client
*   **Plan:** 6 Months (Medium)
*   **Down Payment:** $200 (20% - Standard)
*   **Principal Financed:** $800
*   **Rate:** 5.0%
*   **Fee Calculation:** $15 + ($800 × 0.05) = **$55.00**

#### Scenario C: The "High Risk" Client
*   **Plan:** 12 Months (Long)
*   **Down Payment:** $50 (5% - Low)
*   **Principal Financed:** $950
*   **Rate:** 9.0%
*   **Fee Calculation:** $15 + ($950 × 0.09) = **$100.50**

## Code Reference

The logic is contained in `app/Livewire/PaymentFlow.php`:

```php
public function calculatePaymentPlanFee()
{
    // ... Implementation of the matrix logic ...
}
```
