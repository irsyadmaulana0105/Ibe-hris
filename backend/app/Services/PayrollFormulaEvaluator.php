<?php

namespace App\Services;

use App\Models\PayrollFormulaComponent;
use Illuminate\Support\Facades\Log;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/**
 * Evaluates payroll formula components stored in `payroll_formula_components`
 * against runtime context (basic_salary, gross, working_days, etc).
 *
 * Formulas use the {variable} syntax visible to admins in Settings → Payroll
 * Formula. We translate to plain Symfony ExpressionLanguage at evaluation
 * time so the underlying engine is safe (no eval, no PHP code).
 *
 * Special functions:
 *   progressive(taxable_income) - applies PMK 168/2023 PPh21 brackets
 *   bracket(amount, [from1,to1,rate1, from2,to2,rate2, ...]) - generic
 *
 * Caller: PayrollService::calculate() builds context, then iterates
 * earning components first, then deductions, accumulating gross/deduction
 * totals.
 */
class PayrollFormulaEvaluator
{
    private ExpressionLanguage $expr;

    public function __construct()
    {
        $this->expr = new ExpressionLanguage();

        // Register custom functions exposed to formulas
        $this->expr->register('progressive',
            fn (string $arg) => sprintf('progressive_pph21(%s)', $arg),
            fn (array $vars, $amount) => $this->progressivePph21((float) $amount),
        );

        $this->expr->register('bracket',
            fn (string $arg, string $brackets) => sprintf('bracket_eval(%s, %s)', $arg, $brackets),
            fn (array $vars, $amount, array $brackets) => $this->bracketEval((float) $amount, $brackets),
        );
    }

    /**
     * Evaluate all components of a category against context.
     *
     * @return array{components: array<int,array{name:string,formula:string,value:float,category:string,taxable:bool}>, total: float}
     */
    public function evaluateCategory(int $companyId, string $category, array $context): array
    {
        $components = PayrollFormulaComponent::query()
            ->where('company_id', $companyId)
            ->where('category', $category)
            ->where(function ($q) {
                // Only evaluate user-added components; seeded defaults are already
                // baked into the hardcoded base calculation in PayrollService.
                $q->where('is_default', false)->orWhereNull('is_default');
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $results = [];
        $total = 0.0;
        $runningContext = $context;
        $currentWorkingDays = (int) ($context['working_days'] ?? 0);

        foreach ($components as $comp) {
            // Threshold check: kalau min_working_days di-set, komponen hanya berlaku
            // saat working_days >= threshold. Lebih cepat & explicit daripada
            // pakai ternary di formula (yang juga tetap didukung).
            $minDays = $comp->min_working_days;
            if ($minDays !== null && $currentWorkingDays < (int) $minDays) {
                $value = 0.0;
            } else {
                $value = $this->evaluate($comp->formula, $runningContext);
            }
            $results[] = [
                'name'             => $comp->name,
                'formula'          => $comp->formula,
                'value'            => $value,
                'category'         => $comp->category,
                'taxable'          => (bool) $comp->taxable,
                'min_working_days' => $minDays !== null ? (int) $minDays : null,
                'skipped_by_threshold' => $minDays !== null && $currentWorkingDays < (int) $minDays,
            ];
            $total += $value;
            // Each successive component can reference previous totals via context
            $runningContext[$this->slugify($comp->name)] = $value;
        }

        return ['components' => $results, 'total' => $total];
    }

    /**
     * Evaluate a single formula string. Returns 0 if expression fails (logs warning).
     */
    public function evaluate(string $formula, array $context): float
    {
        // Translate {variable} → variable (Symfony expression syntax)
        $translated = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '$1', $formula);

        try {
            $result = $this->expr->evaluate($translated, $context);
            return is_numeric($result) ? (float) $result : 0.0;
        } catch (\Throwable $e) {
            Log::warning("Payroll formula failed: '{$formula}' - " . $e->getMessage());
            return 0.0;
        }
    }

    /**
     * PMK 168/2023 PPh21 progressive brackets (annual).
     * Layer 1: 0–60jt        → 5%
     * Layer 2: 60–250jt      → 15%
     * Layer 3: 250–500jt     → 25%
     * Layer 4: 500jt–5M      → 30%
     * Layer 5: > 5M          → 35%
     */
    private function progressivePph21(float $taxableIncome): float
    {
        if ($taxableIncome <= 0) return 0.0;

        $brackets = [
            [0,           60_000_000,    0.05],
            [60_000_000,  250_000_000,   0.15],
            [250_000_000, 500_000_000,   0.25],
            [500_000_000, 5_000_000_000, 0.30],
            [5_000_000_000, PHP_FLOAT_MAX, 0.35],
        ];

        $tax = 0.0;
        foreach ($brackets as [$from, $to, $rate]) {
            if ($taxableIncome <= $from) break;
            $slice = min($taxableIncome, $to) - $from;
            $tax += $slice * $rate;
        }
        return $tax;
    }

    /**
     * Generic bracket evaluator for custom progressive rates.
     */
    private function bracketEval(float $amount, array $brackets): float
    {
        $result = 0.0;
        $chunks = array_chunk($brackets, 3);
        foreach ($chunks as [$from, $to, $rate]) {
            if ($amount <= $from) break;
            $slice = min($amount, $to) - $from;
            $result += $slice * $rate;
        }
        return $result;
    }

    private function slugify(string $s): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $s));
    }
}
