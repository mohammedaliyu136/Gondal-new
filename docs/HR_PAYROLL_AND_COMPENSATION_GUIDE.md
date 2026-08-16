# Gondal ERP — HR, Dynamic Compensation & Payroll Architecture Guide

> **Confidential & Proprietary** &mdash; Gondal Fulbe Development Co.  
> **Module Version:** 2.4.0 &middot; **Last Updated:** August 2026

---

## Table of Contents
1. [Overview & Architecture Philosophy](#1-overview--architecture-philosophy)
2. [Compensation & Salary Structure (Master Data)](#2-compensation--salary-structure-master-data)
3. [Dynamic Staff Loans & Cash Advance Subsystem](#3-dynamic-staff-loans--cash-advance-subsystem)
4. [Variable Earnings Subsystem (Commissions & Overtime)](#4-variable-earnings-subsystem-commissions--overtime)
5. [HR Setup & Master Configuration Hub (`/hr/setup`)](#5-hr-setup--master-configuration-hub-hrsetup)
6. [Live Current-Month Salary Projection Engine](#6-live-current-month-salary-projection-engine)
7. [Enterprise Pre-Submission Payroll Lifecycle (`/payroll`)](#7-enterprise-pre-submission-payroll-lifecycle-payroll)
8. [Access Control & Permission Matrix](#8-access-control--permission-matrix)
9. [Developer & Operations Cheat Sheet](#9-developer--operations-cheat-sheet)

---

## 1. Overview & Architecture Philosophy

Gondal ERP replaces static, rigid salary profiles with a **hybrid, event-driven compensation and payroll engine**.

Compensation is structured in two distinct layers:
1. **Fixed Baseline Structure**: Contractual monthly base wage, fixed recurring allowances, and statutory deduction parameters.
2. **Dynamic Transactional Ledgers**: Transaction-based events (staff loans, cash advances, performance commissions, and overtime shift logs) that automatically flow into upcoming monthly payroll runs.

```
┌──────────────────────────────────────────────────────────────────────────┐
│                   EMPLOYEE COMPENSATION MASTER DATA                      │
├─────────────────────────────────────┬────────────────────────────────────┤
│       1. Fixed Salary Profile       │    2. Dynamic Transactional Pay    │
│  - Basic Salary                     │  - Active Staff Loan Deductions    │
│  - Housing, Transport, Utility      │  - Performance Commissions (Queued)│
│  - Medical & Other Allowances       │  - Overtime Shift Logs (Queued)    │
│  - Statutory Pension & PAYE Rules   │                                    │
└──────────────────┬──────────────────┴──────────────────┬─────────────────┘
                   │                                     │
                   ▼                                     ▼
        ┌───────────────────────────────────────────────────────┐
        │            MONTHLY PAYROLL ENGINE (/payroll)          │
        │  - Aggregates Fixed + Variable                        │
        │  - Applies Statutory Pension & Tax Formulas           │
        │  - Amortizes Loans & Marks Variable Items Processed   │
        └───────────────────────────────────────────────────────┘
```

---

## 2. Compensation & Salary Structure (Master Data)

Located at `/employees/{id}/salary` and modeled via `App\Models\EmployeeSalaryProfile`.

### 2.1 Fixed Earnings Components (Stored in Minor Units / Kobo)
- `basic_salary_minor`: Core taxable base wage.
- `housing_allowance_minor`: Monthly accommodation subsidy.
- `transport_allowance_minor`: Commute and transit subsidy.
- `utility_allowance_minor`: Utility, meal, and data subsidy.
- `medical_allowance_minor`: Health and medical support.
- `other_allowance_minor`: Special duty, hazard, or responsibility allowance.

$$\text{Base Gross} = \text{Basic} + \text{Housing} + \text{Transport} + \text{Utility} + \text{Medical} + \text{Other}$$

### 2.2 Statutory & Regular Deductions
- **Pension Contribution**: Configured as percentage (`pension_rate_pct`, default 8.0%), with `is_pension_exempt` toggle.
  $$\text{Pension} = \begin{cases} 0 & \text{if exempt} \\ \text{round}(\text{Base Gross} \times \text{Rate}) & \text{otherwise} \end{cases}$$
- **PAYE Income Tax**: Configured as percentage (`tax_rate_pct`, default 7.0%), with `is_tax_exempt` toggle. Applied on taxable gross after pension deduction:
  $$\text{PAYE Tax} = \begin{cases} 0 & \text{if exempt} \\ \text{round}((\text{Base Gross} - \text{Pension}) \times \text{Rate}) & \text{otherwise} \end{cases}$$
- **Voluntary Regular Deductions**:
  - `nhis_minor`: National Health Insurance Scheme check-off.
  - `union_dues_minor`: Union or cooperative contributions.
  - `other_deduction_minor`: Miscellaneous recurring payroll deductions.

---

## 3. Dynamic Staff Loans & Cash Advance Subsystem

Modeled via `App\Models\StaffLoan` and `App\Models\StaffLoanRepayment`.

### 3.1 Features
- **Schemes**: Configured in HR Setup (e.g., *Salary Advance*, *Emergency Medical Loan*, *Vehicle Repair Advance*).
- **Auto-Amortization**: Automatically deducts `monthly_installment_minor` on each payroll run until `balance_minor = 0`.
- **Status Lifecycle**: `active` &rarr; `paused` &rarr; `completed` (or `written_off`).
- **Repayment Desk**: Supports out-of-band direct settlements at the finance desk (`/staff-loans/{loan}/repay`).

---

## 4. Variable Earnings Subsystem (Commissions & Overtime)

Modeled via `App\Models\EmployeeCommission` and `App\Models\EmployeeOvertime`.

### 4.1 Lifecycle
1. **Queued for Payroll (`payslip_id = null`)**: Logged during the month (e.g., night shift overtime, sales target milestone).
2. **Attached to Draft Payslip (`payslip_id = X`, `status = 'pending'`)**: Included as itemized earnings line on the monthly run.
3. **Processed in Payroll (`status = 'processed_in_payroll'`)**: Locked once payroll is authorized and paid.

---

## 5. HR Setup & Master Configuration Hub (`/hr/setup`)

A centralized configuration interface formatted with standardized navigation tabs:

| Tab | Route / Key | Capabilities |
| :--- | :--- | :--- |
| **Staff Loan Schemes** | `?tab=loans` | Create and manage loan schemes, interest rules, and repayment limits. |
| **Commission Milestones** | `?tab=commissions` | Configure performance bonus categories and milestone triggers. |
| **Allowances & Overtime** | `?tab=allowances` | Define non-taxable vs taxable allowances and hourly overtime rate bands. |
| **Leave Entitlements** | `?tab=leave_types` | Manage leave categories, paid/unpaid statuses, and annual day allocations. |
| **Deduction Schemes** | `?tab=deductions` | Configure cooperative dues and institutional check-offs. |
| **Departments** | `?tab=departments` | Manage organizational departments and line hierarchy. |

---

## 6. Live Current-Month Salary Projection Engine

To eliminate ambiguity between contractual baseline pay and dynamic payouts, the system displays two synchronized layers:

```
[Regular Base Gross]    [+ Variable Pay (Aug 2026)]    [- Total Deductions (Aug 2026)]    [= Estimated Net Payout]
    ₦250,000.00                   +₦8,000.00                    -₦11,500.00                    ₦246,500.00
```

1. **Base Fixed Contract Net Take-Home** (Contractual Baseline):
   $$\text{Base Net} = \text{Base Gross} - \text{Regular Statutory Deductions (Pension + PAYE)}$$
2. **Estimated Net Bank Disbursement for Current Month**:
   $$\text{Current Month Net} = (\text{Base Gross} + \text{Queued Commissions} + \text{Queued Overtime}) - (\text{Regular Deductions} + \text{Active Loan Installment})$$

---

## 7. Enterprise Pre-Submission Payroll Lifecycle (`/payroll`)

Prior to submitting a payroll run for multi-stage approval, HR and Finance officers can perform real-time adjustments on draft runs:

```
                          ┌───────────────────────────┐
                          │     Draft Payroll Run     │
                          │   (Period Open/Unlocked)  │
                          └─────────────┬─────────────┘
                                        │
        ┌───────────────────┬───────────┴───────────┬───────────────────┐
        ▼                   ▼                       ▼                   ▼
┌───────────────┐   ┌───────────────┐       ┌───────────────┐   ┌───────────────┐
│  Recalculate  │   │  Add Missing  │       │ Remove/Exclude│   │ Regenerate /  │
│ Single Payslip│   │   Employee    │       │   Employee    │   │ Discard Run   │
└───────────────┘   └───────────────┘       └───────────────┘   └───────────────┘
```

### 7.1 Available Pre-Submission Actions

| Action | Endpoint / Method | Description |
| :--- | :--- | :--- |
| **↻ Recalculate Payslip** | `POST /payroll/payslips/{id}/recalculate` | Re-evaluates a single draft payslip against latest salary structure, loan installment, and queued commissions; refreshes run totals. |
| **× Remove from Run** | `DELETE /payroll/payslips/{id}` | Removes an employee from the draft run (e.g. unpaid leave, suspension); unlinks variable pay records back to unbilled queue. |
| **+ Add Staff to Run** | `POST /payroll/{run}/add-employee` | Selects an active on-payroll employee not currently on the run, computes their payslip, and appends them to the run. |
| **↻ Sync Master Data** | `POST /payroll/{run}/sync` | Batch recalculates all draft payslips against active salary profiles and synchronizes run statistics. |
| **🗑 Discard Draft Run** | `DELETE /payroll/{run}` | Deletes all draft payslips, unlinks attached variable records, and deletes the draft run so the period can be re-generated cleanly. |

---

## 8. Gateway-Backed Bank Account Verification Subsystem

To eliminate failed payroll disbursements caused by typos or invalid accounts, employee registration and edits now enforce real-time bank verification via the **default payment gateway configured in `/settings`** (Paystack, Monnify, or Zainpay).

```
[Select Nigerian Bank] ──► [Input 10-Digit NUBAN] ──(on blur)──► [POST /employees/verify-bank]
                                                                        │
                   ┌────────────────────────────────────────────────────┤
                   ▼                                                    ▼
       [Configured Default Gateway]                         [Automatic Resolution]
       - Paystack: /bank/resolve                            - Auto-populates Read-Only
       - Monnify: /account/validate                           "Account Holder Name"
       - Zainpay: /bank/name-enquiry                        - Renders ✓ Verified Badge
```

### 8.1 Workflow
1. **Bank Selection**: HR officer selects a CBN-recognized bank from the bank dropdown (Access Bank, GTBank, First Bank, Zenith, UBA, OPay, PalmPay, Moniepoint, etc.).
2. **NUBAN Input**: The officer enters the 10-digit NUBAN account number.
3. **On Blur / 10-Digit Trigger**:
   - AJAX request triggers `POST /employees/verify-bank`.
   - Resolves the account name against the default payment gateway.
   - Automatically populates the read-only, required **`Account Holder Name`** input field.
   - Renders a green verification badge (`✓ Verified: [FULL NAME]`).
   - If invalid, alerts the officer and clears the name to prevent saving unverified payout targets.

---

## 9. Access Control & Permission Matrix

All HR and Payroll routes are protected by fine-grained permissions:

| Module | Permission Key | Classification | Description |
| :--- | :--- | :--- | :--- |
| **Payroll View** | `hr.payroll.view` | Standard | View payroll runs, summaries, and staff compensation figures. |
| **Payroll Modify** | `hr.payroll.create`, `hr.payroll.edit` | **Sensitive** | Edit salary structures, grant loans, record commissions, generate/adjust draft runs. |
| **Payroll Approve** | `hr.payroll.approve` | **Sensitive** | Approve submitted payroll runs and authorize payment releases. |
| **HR Master Setup** | `hr.setup.view`, `hr.setup.create`, `hr.setup.edit` | Administrative | Manage loan schemes, commission types, allowance rules, and leave entitlements. |

---

## 10. Developer & Operations Cheat Sheet

### Useful Artisan Commands
```bash
# Clear and warm Blade view cache
php artisan view:clear && php artisan view:cache

# Clear application config and route cache
php artisan optimize:clear && php artisan route:cache

# Run HR & Dynamic Compensation tests
php artisan test --filter=Payroll
```

### Relevant Code References
- **Bank Service**: [`app/Services/Payment/BankService.php`](file:///c:/wamp64/www/devgondal/app/Services/Payment/BankService.php)
- **Employee Controller**: [`app/Http/Controllers/Hr/EmployeeController.php`](file:///c:/wamp64/www/devgondal/app/Http/Controllers/Hr/EmployeeController.php)
- **Employee Service**: [`app/Services/Hr/EmployeeService.php`](file:///c:/wamp64/www/devgondal/app/Services/Hr/EmployeeService.php)
- **Payroll Service**: [`app/Services/Hr/PayrollService.php`](file:///c:/wamp64/www/devgondal/app/Services/Hr/PayrollService.php)
- **Disbursement Service**: [`app/Services/Payment/Modules/PayrollPaymentService.php`](file:///c:/wamp64/www/devgondal/app/Services/Payment/Modules/PayrollPaymentService.php)
- **Salary View**: [`resources/views/hr/employees/salary.blade.php`](file:///c:/wamp64/www/devgondal/resources/views/hr/employees/salary.blade.php)
- **Employee Register View**: [`resources/views/hr/employees/index.blade.php`](file:///c:/wamp64/www/devgondal/resources/views/hr/employees/index.blade.php)
- **Employee Profile View**: [`resources/views/hr/employees/show.blade.php`](file:///c:/wamp64/www/devgondal/resources/views/hr/employees/show.blade.php)
- **Payroll View**: [`resources/views/hr/payroll/index.blade.php`](file:///c:/wamp64/www/devgondal/resources/views/hr/payroll/index.blade.php)

