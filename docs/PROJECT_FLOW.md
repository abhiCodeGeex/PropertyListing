# Property Listing Project Flow

## 1. Project Overview

Property Listing is a role-based property rental management system that supports:

- user authentication and profile completion
- property creation and assignment
- rent deed creation
- tenant rent payments and auto-pay subscriptions
- security deposit collection
- owner or property manager approval for manual payments
- invoice generation and payment reporting
- in-app notifications for operational events

The project works around one central relationship:

- `Property` belongs to an `Owner`
- `Property` can optionally have a `Property Manager`
- `Property` is assigned to one or more `Tenants`
- the tenant assignment creates a `Tenancy` record in `property_tenant`
- a `Rent Deed` is created for the property and tenant
- rent schedules are generated from the tenancy and rent deed
- tenant payments, approvals, invoices, and reports all depend on that chain

Core sequence:

1. User signs up or is created by admin.
2. User verifies email.
3. User logs in.
4. User completes profile.
5. Owner creates property.
6. Owner optionally assigns property manager.
7. Owner assigns tenant to property.
8. Owner or manager creates rent deed.
9. System generates rent schedules.
10. Tenant pays rent and security deposit, either by Stripe or manual approval flow.
11. System generates invoices, notifications, and reports.

## 2. Roles in the System

### Public signup roles

- Owner
- Tenant

### Admin-created or seeded roles

- Super Admin
- Property Manager
- Agent

### What each role can generally do

| Role | Main Purpose |
|---|---|
| Super Admin | Full platform access, users, properties, reports, invoices |
| Owner | Create and manage own properties, assign tenants and managers, create rent deeds, approve manual payments |
| Property Manager | Manage assigned properties, create rent deeds, view reports, approve manual payments for managed properties |
| Tenant | View assigned property, pay rent, pay overdue dues, activate auto-pay, pay security deposit, request manual approvals, view invoices and rent history |
| Agent | Exists as a role in seed data, but no dedicated route or workflow is exposed in the current application |

## 3. Authentication and Account Flow

### 3.1 Signup flow

Public signup is available only for `owner` and `tenant`.

Step-by-step:

1. User opens registration page.
2. User enters name, email, password, password confirmation, captcha, and role.
3. Backend validates:
   - name required
   - unique email
   - password minimum length and confirmation
   - captcha required
   - role must be `owner` or `tenant`
4. Backend verifies Google reCAPTCHA.
5. Backend creates the user.
6. Backend generates a unique username from the display name.
7. Backend assigns the selected role.
8. Backend creates a welcome notification.
9. Backend sends email verification notification.
10. User receives message: signup successful, verification email sent.

### 3.2 Email verification flow

1. User clicks verification link from email.
2. Backend checks:
   - user exists
   - email hash is valid
   - signed URL is valid and not expired
3. Backend marks email as verified.
4. User can now log in.

If the user tries to log in before verifying email:

- login is blocked with `Please verify your email before logging in.`

### 3.3 Login flow

1. User opens login page.
2. User enters email and password.
3. Backend validates credentials.
4. Backend checks email verification.
5. Backend issues API token.
6. Frontend stores:
   - token
   - user
   - roles
   - profile
7. Frontend loads `/me` to refresh full user, roles, and profile data.

### 3.4 Logout flow

1. User clicks logout.
2. Backend deletes all API tokens for that user.
3. Frontend clears local storage and local auth state.

### 3.5 Forgot password and reset password flow

1. User submits email on forgot password page.
2. Backend sends password reset link.
3. User opens reset link.
4. User submits token, email, new password, and confirmation.
5. Backend resets the password.

### 3.6 Social login flow

Supported providers:

- Google
- Facebook

Flow:

1. User clicks social login button.
2. Frontend redirects to backend social login route.
3. Backend redirects to provider.
4. Provider returns callback.
5. Backend completes authentication flow.

## 4. Mandatory Profile Completion Flow

After login, the app enforces profile completion before most protected pages can be used.

Profile fields include:

- first name
- last name
- username
- optional password update
- date of birth
- current address
- native address
- Aadhaar number
- Aadhaar verified text
- PAN
- marital status
- gender
- phone
- profile image

Step-by-step:

1. User logs in.
2. Frontend checks whether profile is completed.
3. If incomplete, user is redirected to `/profile` before most routes.
4. User fills profile.
5. Backend validates and stores profile.
6. User can then continue using the rest of the system.

Additional profile actions:

- generate Aadhaar OTP
- verify Aadhaar OTP
- send email change OTP
- verify email change OTP

## 5. Main Business Entities

### 5.1 User

Represents a logged-in account with one or more roles.

### 5.2 Profile

Stores personal identity and contact details.

### 5.3 Property

Stores owner-controlled rental asset details such as:

- property name
- type
- address
- monthly rent
- payment mode
- security amount
- agreement duration
- late payment penalty
- electricity bill responsibility

### 5.4 Tenancy

Created when a tenant is assigned to a property. It stores:

- start date
- end date
- security deposit amount
- security deposit status
- Stripe customer and subscription references
- auto-pay state

### 5.5 Rent Deed

Defines rental agreement details such as:

- agreement number
- agreement date
- rent due date
- maintenance charges
- other terms

Rent schedules are generated only when the tenancy and rent deed are in place.

### 5.6 Rent Schedule

Monthly due entries used to track:

- pending rent
- overdue rent
- manual pending approvals
- paid rent

### 5.7 Payment

Stores payment attempts and results for:

- rent deposit
- overdue rent
- late fee
- security deposit

### 5.8 Invoice

Generated for successful rent and deposit payments and made visible to authorized users.

## 6. Super Admin Flow

Super Admin is the highest access role.

### What Super Admin can access

- dashboard
- users
- properties
- rent deeds
- rent history
- invoices
- payment report

### Super Admin flow

1. Super Admin logs in.
2. Completes profile if needed.
3. Can create, update, and delete users.
4. Can assign roles to users.
5. Can view all properties across the platform.
6. Can create or manage properties.
7. Can create rent deeds.
8. Can view invoices for all accessible records.
9. Can view revenue report across all successful payments.
10. Can monitor notifications and operational state.

### Typical Super Admin actions

- create owner, tenant, or property manager accounts
- assign or change user roles
- create property on behalf of owner
- inspect invoices and payment records
- review owner or manager workflows when debugging issues

## 7. Owner Flow

Owner is the main business operator for a property.

### Owner onboarding flow

1. Owner signs up publicly or is created by Super Admin.
2. Owner verifies email.
3. Owner logs in.
4. Owner completes profile.

### Owner property management flow

1. Owner opens Properties page.
2. Owner creates a property with:
   - property details
   - rent amount
   - payment mode
   - security amount
   - late payment penalty
3. Property is saved under that owner.
4. Owner can edit or delete the property later.

### Owner manager assignment flow

1. Owner opens a property.
2. Owner selects a property manager.
3. Backend validates selected user has `property_manager` role.
4. Property is updated with `manager_id`.
5. Notifications are sent to relevant stakeholders.

### Owner tenant assignment flow

1. Owner selects a property.
2. Owner assigns one or more tenants with:
   - start date
   - end date
3. System creates tenancy records in `property_tenant`.
4. Security deposit amount is copied from property into tenancy.
5. Security deposit status starts as `pending`.
6. If a rent deed already exists, rent schedules are generated.
7. Notifications are sent to tenant, owner, and manager.

### Owner rent deed flow

1. Owner opens Rent Deeds page.
2. Owner creates rent deed for a property.
3. Backend checks:
   - property exists
   - tenant is assigned to property
   - owner exists for property
4. Rent deed is saved.
5. System generates rent schedules for the latest tenancy.
6. System updates overdue states as needed.

### Owner manual security deposit approval flow

1. Tenant chooses manual security deposit option.
2. Tenancy status becomes `manual_pending`.
3. A pending manual payment record is created.
4. Owner sees the request in security deposit approvals.
5. Owner approves or declines.
6. If approved:
   - payment status becomes `succeeded`
   - tenancy security deposit status becomes `paid`
   - invoice is issued
   - notifications are sent
7. If declined:
   - payment status becomes `rejected`
   - tenancy security deposit returns to `pending`

### Owner manual rent approval flow

1. Tenant requests manual rent deposit approval.
2. All due unpaid rent schedules for that tenancy are changed to `manual_pending`.
3. Pending payment records are created for those schedules.
4. Owner sees the request in rent approvals.
5. Owner approves or declines each request.
6. If approved:
   - rent schedule becomes `paid`
   - payment record becomes `succeeded`
   - invoice is issued
   - notifications are sent
7. If declined:
   - rent schedule returns to `pending`
   - payment record becomes `rejected`

### Owner reporting flow

1. Owner opens Payment Report.
2. System shows successful payments for owner properties only.
3. Owner can filter by date and property search.
4. Total revenue is calculated from successful payments.

### Owner invoice flow

1. Owner opens Invoices.
2. System shows invoices linked to owner properties.
3. Owner can:
   - view invoice
   - download PDF
   - resend invoice email

## 8. Property Manager Flow

Property Manager works like an operational extension of the owner, but only for assigned properties.

### How Property Manager account is created

- created by Super Admin or existing admin process
- not available through public signup

### Property Manager access

- dashboard
- properties for managed properties only
- rent deeds for managed properties
- rent history for managed properties
- invoices for managed properties
- payment report for managed properties

### Property Manager flow

1. Property Manager logs in.
2. Completes profile if needed.
3. Views only properties where `manager_id` matches their user id.
4. Can update managed property information.
5. Can view property tenants.
6. Can create and update rent deeds for managed properties.
7. Can approve manual security deposit requests.
8. Can approve manual rent requests.
9. Can review invoices and revenue reports for managed properties.

## 9. Tenant Flow

Tenant is the payment-facing role in the system.

### Tenant onboarding flow

1. Tenant signs up publicly or is created by admin.
2. Tenant verifies email.
3. Tenant logs in.
4. Tenant completes profile.

### How tenant becomes active in the rental workflow

The tenant does not become operational just by signing up.

Required setup:

1. Owner creates property.
2. Owner assigns tenant to property.
3. Owner or manager creates rent deed.
4. System generates rent schedule.
5. Tenant dashboard now shows property and payment actions.

If assignment or rent deed is missing:

- tenant may log in successfully
- but payment actions are not available until the setup is complete

### Tenant dashboard flow

Tenant dashboard is driven by assigned property and tenancy data.

The dashboard can show:

- property information
- monthly rent
- due amount
- overdue amount
- auto-pay status
- security deposit status
- payment actions
- scheduled cancellation state

### Tenant monthly rent payment flow with Stripe

This is the standard pay-and-subscribe flow for card-enabled properties.

1. Tenant opens payment flow for current due rent.
2. Backend fetches all payable schedules due up to end of current month.
3. Backend calculates late fee based on property late payment penalty.
4. Backend creates Stripe payment intent.
5. Pending payment rows are created for:
   - current rent schedules
   - late fee, if applicable
6. Tenant confirms payment in Stripe.
7. After successful payment, system creates monthly Stripe subscription for future auto-pay.
8. Tenancy is marked with:
   - `stripe_subscription_id`
   - `subscription_active = 1`
9. Notifications are sent.
10. Future rent is charged automatically based on billing anchor.

### Tenant overdue payment flow

1. Tenant opens overdue payment modal.
2. Backend loads all unpaid due schedules up to current month.
3. Backend calculates late fee.
4. Stripe payment intent is created for total overdue amount plus late fee.
5. Pending payment rows are created.
6. Tenant pays overdue amount.
7. If auto-pay is not yet active, the same card can be used to activate auto-pay for future months.
8. Successful settlement clears overdue dues and records payment.

### Tenant auto-pay only flow

If tenant wants future monthly charging without first-time subscription activation through another payment:

1. Tenant provides payment method.
2. Backend ensures Stripe customer and price exist.
3. Backend attaches the payment method to the Stripe customer.
4. Backend creates Stripe subscription.
5. Subscription starts with trial until next billing anchor.
6. Tenancy is marked as auto-pay active.

### Tenant cancel auto-pay flow

1. Tenant chooses cancel subscription.
2. Backend verifies tenancy is accessible.
3. If Stripe subscription exists, system schedules cancellation at end of current month.
4. Tenancy is updated with:
   - `subscription_active = 3`
   - `subscription_cancel_at = month end`
5. Notifications are sent.

Important:

- cancellation is scheduled for month end, not immediate, in the standard app flow

### Tenant security deposit flow with Stripe

1. Tenant sees pending security deposit.
2. Tenant clicks pay security deposit.
3. Backend validates:
   - tenancy belongs to tenant
   - rent deed exists
   - security deposit amount is configured
   - deposit has not already been paid
4. Stripe payment intent is created.
5. Pending security deposit payment row is created.
6. Tenant completes Stripe payment.
7. On successful webhook settlement:
   - security deposit status becomes paid
   - payment becomes succeeded
   - invoice is generated
   - notifications are sent

### Tenant manual security deposit request flow

Used when payment mode or real-world process requires owner approval.

1. Tenant chooses manual security deposit request.
2. Backend checks for existing pending request.
3. Tenancy security deposit status becomes `manual_pending`.
4. Pending manual payment row is created.
5. Notifications are sent to owner and manager.
6. Owner or manager approves or declines later.

### Tenant manual rent request flow

1. Tenant chooses manual rent approval request.
2. Backend finds all unpaid due schedules up to end of current month.
3. Those schedules become `manual_pending`.
4. Pending manual payment rows are created.
5. Notifications are sent.
6. Owner or manager approves or declines later.

### Tenant invoices and rent history flow

1. Tenant opens Rent History page to review payment-related rent records.
2. Tenant opens Invoices page to review generated invoices.
3. Tenant can view and download invoices addressed to them.

## 10. Property Creation and Assignment Flow

This is the main operational setup flow in the system.

1. Owner creates property.
2. Owner optionally assigns property manager.
3. Owner assigns tenant to property.
4. System creates tenancy record.
5. Security deposit amount is copied to tenancy.
6. Security deposit status starts as pending.
7. Owner or manager creates rent deed.
8. System generates monthly rent schedules.
9. Tenant can now pay rent and deposit.

This is the most important setup dependency chain in the application.

If any step is missing:

- tenant dashboard may not show the expected actions
- rent payment may not start
- auto-pay cannot be activated properly

## 11. Payment Modes and How They Affect Flow

Each property stores a `payment_mode`.

Supported values:

- `UPI`
- `Cash`
- `Credit/Debit Cards`

### Card payment flow

If payment mode is `Credit/Debit Cards`:

- tenant pays through Stripe
- rent can activate auto-pay
- security deposit can be paid through Stripe

### Manual payment flow

If payment mode is manual-like such as cash or offline handling:

- tenant can request manual rent approval
- tenant can request manual security deposit approval
- owner or property manager approves or declines

## 12. Late Payment and Overdue Flow

Late fee logic is based on property late payment penalty.

### Overdue calculation flow

1. System checks rent schedules due up to end of current month.
2. Any unpaid due schedule is treated as payable now or overdue.
3. Late fee policy service calculates applicable late fee.
4. Dashboard and overdue modal show:
   - base rent total
   - late fee total
   - total payable

### Settlement flow

1. Payment intent metadata stores schedule ids and late fee snapshot.
2. Payment succeeds in Stripe.
3. Webhook finalizes payment.
4. Rent schedules are updated to paid.
5. Late fee payment is recorded correctly.
6. Invoice can be issued.

This protects payment accuracy even if late fee policy changes after payment intent creation.

## 13. Stripe Auto-Pay Subscription Flow

The system supports recurring monthly auto-pay using Stripe subscriptions.

### Subscription activation paths

- pay current due rent and start auto-pay
- pay overdue rent and start auto-pay
- activate auto-pay directly with saved card

### Subscription setup flow

1. System ensures Stripe secret and rent product are configured.
2. System ensures Stripe customer exists for tenancy.
3. System resolves or creates Stripe price based on monthly rent.
4. System attaches payment method to customer.
5. System saves default payment method.
6. System creates subscription.
7. Subscription uses a billing anchor aligned to the rent due cycle.
8. Tenancy stores Stripe subscription id and active state.

### Subscription states in tenancy

- `1` = active
- `3` = scheduled for cancellation
- `2` has been used in the app as inactive or canceled state during manual cleanup and non-active scenarios

## 14. Security Deposit Flow

Security deposit belongs to the tenancy and is copied from property at the time of tenant assignment.

### Possible statuses

- `pending`
- `manual_pending`
- `paid`

### Deposit lifecycle

1. Owner assigns tenant.
2. Tenancy inherits property security amount.
3. Deposit status starts as `pending`.
4. Tenant either:
   - pays through Stripe
   - requests manual approval
5. Owner or manager may approve manual request.
6. On success, deposit status becomes `paid`.
7. Invoice is generated for successful deposit payment.

## 15. Invoices Flow

Invoices are generated for successful payment events.

### Who can access invoices

- Super Admin
- invoice recipient
- Owner of related property
- Property Manager of related property

### Invoice actions

- list invoices
- view invoice details
- download PDF
- resend invoice email

### Typical invoice creation triggers

- successful rent payment
- successful security deposit payment
- successful approved manual payment

## 16. Notifications Flow

Notifications are created for important business events.

Examples:

- signup welcome
- tenant assigned to property
- manager assigned to property
- subscription activated
- subscription cancellation scheduled
- security deposit manual request
- security deposit approved or rejected
- rent manual request
- rent approved or rejected

### Notification behavior

1. Backend creates notification on event.
2. User sees notifications in header panel.
3. User can fetch paginated notifications.
4. User can mark a notification as read.

## 17. Reports Flow

Revenue report is based on successful payments.

### Access

- Super Admin: all successful payments
- Owner: only their properties
- Property Manager: only managed properties

### Report filters

- from date
- to date
- search by property name
- pagination

### Report output

- payment list
- property relation
- total revenue

## 18. Dashboard Behavior by Role

### Tenant dashboard

Shows tenancy-driven operational actions:

- assigned property
- current rent status
- overdue rent
- auto-pay state
- security deposit action
- cancellation action

### Owner or Property Manager dashboard

Shows approval-oriented and management-oriented information instead of tenant payment actions.

### If no records are available

- Tenant sees empty state for no assigned property
- Owner or Property Manager sees empty state related to approvals or business data, not tenant wording

## 19. Important Setup Dependencies

For the application to work correctly, these dependencies matter:

### Tenant cannot pay rent unless

- tenant is assigned to property
- tenancy exists
- rent deed exists
- rent schedules exist

### Auto-pay cannot start unless

- Stripe is configured
- tenancy exists
- rent deed exists
- Stripe customer exists or can be created
- valid payment method is attached

### Security deposit payment cannot start unless

- tenancy belongs to tenant
- rent deed exists
- security deposit amount is greater than zero
- deposit is not already paid

### Manual approval flows depend on

- owner or manager being assigned correctly
- pending payment records being created
- approval endpoints being used by authorized roles only

## 20. End-to-End Example Flows

### Example A: Standard owner to tenant rent flow

1. Owner signs up and verifies email.
2. Owner logs in and completes profile.
3. Owner creates property.
4. Owner assigns tenant.
5. Owner creates rent deed.
6. System generates rent schedule.
7. Tenant signs up, verifies email, logs in, completes profile.
8. Tenant sees assigned property on dashboard.
9. Tenant pays rent by card.
10. System activates monthly auto-pay.
11. Future rent is charged automatically.
12. Invoice is issued and visible in invoices page.

### Example B: Tenant pays overdue rent and starts auto-pay

1. Tenant has unpaid due rent.
2. Dashboard shows overdue amount with any late fee.
3. Tenant opens overdue payment modal.
4. Tenant pays all overdue rent.
5. Same payment method is reused to activate auto-pay.
6. Tenancy becomes auto-pay active for future months.

### Example C: Manual deposit approval flow

1. Tenant has pending security deposit.
2. Tenant requests manual deposit approval.
3. System creates pending manual payment.
4. Owner or manager reviews pending deposit approvals.
5. Owner or manager approves.
6. Deposit status becomes paid.
7. Invoice is generated.

## 21. Current Functional Boundaries

These points are important when documenting the current project honestly:

- public signup supports only `owner` and `tenant`
- `property_manager` and `super-admin` are operational roles, not public signup roles
- `agent` exists in seed data but does not currently have a visible route-based workflow in the app
- payment and dashboard actions depend heavily on proper tenancy and rent deed setup
- notification, invoice, and reporting features are already integrated into the business flow

## 22. Short Summary

In simple terms, the system works like this:

1. Create users and roles.
2. Verify email and complete profile.
3. Owner creates property.
4. Owner assigns manager if needed.
5. Owner assigns tenant.
6. Owner or manager creates rent deed.
7. System generates rent schedules.
8. Tenant pays rent and security deposit.
9. Owner or manager handles manual approvals where required.
10. System maintains subscriptions, invoices, notifications, and reports.

