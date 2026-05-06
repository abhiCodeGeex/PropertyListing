<?php

use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\FormConfigController;
use App\Http\Controllers\API\InvoiceController;
use App\Http\Controllers\API\ProfileController;
use App\Http\Controllers\API\PropertyCommissionController;
use App\Http\Controllers\API\PropertyController;
use App\Http\Controllers\API\RentDeedController;
use App\Http\Controllers\API\RentHistoryController;
use App\Http\Controllers\API\RentSubscriptionController;
use App\Http\Controllers\API\ReportController;
use App\Http\Controllers\API\RoleController;
use App\Http\Controllers\API\SocialLoginController;
use App\Http\Controllers\API\StripeWebhookController;
use App\Http\Controllers\API\TenantController;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Modules\Chat\Controllers\ChatController;
use App\Modules\Maintenance\Controllers\MaintenanceRequestController;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Http\Controllers\TransientTokenController;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

// Authentication
Route::post('login', [AuthController::class, 'login']);
Route::post('signup', [AuthController::class, 'signup'])->middleware('api');
Route::get('/form-config/{form}', [FormConfigController::class, 'getForm']);

/** Public UI config: currency mirrors STRIPE_CURRENCY + Stripe so displays match invoices/payments. */
Route::get('public-config', function () {
    return response()->json([
        'currency' => [
            'code' => \App\Support\Currency::code(),
            'symbol' => \App\Support\Currency::symbol(),
            'locale' => \App\Support\Currency::locale(),
        ],
    ]);
});

// Password Reset
Route::post('forgot-password', [ForgotPasswordController::class, 'sendResetLinkEmail']);
Route::post('reset-password', [ResetPasswordController::class, 'reset']);

// Social Login
Route::prefix('auth/{provider}')->where(['provider' => 'google|facebook'])->group(function () {
    Route::get('redirect', [SocialLoginController::class, 'redirect']);
    Route::get('callback', [SocialLoginController::class, 'callback']);
});

Route::post('stripe/webhook', [StripeWebhookController::class, 'handle']);
// OAuth
Route::post('oauth/token', [AccessTokenController::class, 'issueToken'])
    ->middleware(['throttle', 'client_credentials', 'guest']);
Route::post('oauth/token/refresh', [TransientTokenController::class, 'refreshToken']);
Route::get('oauth/authorize', [AuthorizationController::class, 'authorize'])
    ->name('passport.authorizations.authorize');
Route::delete('oauth/tokens/{token_id}', [AccessTokenController::class, 'revokeToken'])
    ->middleware('auth:api');
// Roles
Route::resources(['roles' => RoleController::class]);

// Aadhaar Verification
Route::post('aadhaar/generate-otp', [ProfileController::class, 'generateOtp']);
Route::post('aadhaar/verify-otp', [ProfileController::class, 'verifyOtp']);
Route::post('aadhaar/generate-token', [ProfileController::class, 'createSandboxToken']);

// Email Verification
Route::post('email/verification-notification', function (Request $request) {
    $request->validate(['email' => 'required|email|exists:users,email']);

    $user = User::where('email', $request->email)->first();

    if ($user->hasVerifiedEmail()) {
        return response()->json(['message' => 'Email already verified']);
    }

    $user->notify(new \App\Notifications\VerifyEmailNotification);

    return response()->json(['message' => 'Verification link sent!']);
})->middleware('throttle:6,1')->name('verification.send');

Route::get('email/verify/{id}/{hash}', function (Request $request, $id, $hash) {
    $user = User::findOrFail($id);

    if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
        return response()->json(['message' => 'Invalid verification link'], 400);
    }

    if (! $request->hasValidSignature()) {
        return response()->json(['message' => 'Verification link expired'], 400);
    }

    if ($user->hasVerifiedEmail()) {
        return response()->json(['message' => 'Email already verified']);
    }

    $user->markEmailAsVerified();
    event(new Verified($user));

    return response()->json(['message' => 'Email verified successfully']);
})->name('verification.verify');

Route::get('email/verify', function () {
    return response()->json(['message' => 'You must verify your email before continuing.']);
})->middleware('auth:api')->name('verification.notice');

/*
|--------------------------------------------------------------------------
| Protected Routes (auth:api)
|--------------------------------------------------------------------------
*/
Broadcast::routes([
    'middleware' => ['auth:api'],
]);
Route::middleware('auth:api')->group(function () {
    // Auth
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [UserController::class, 'me']);
    Route::get('notifications', [UserController::class, 'myNotifications']);
    Route::post('notifications/{id}/read', [UserController::class, 'markAsRead']);
    Route::post('/users/{user}/assign-role', [UserController::class, 'assignRole']);
    Route::get('users/property/managers', [PropertyController::class, 'managers']);
    Route::post('/users/assigned-properties', [PropertyController::class, 'assignedProperties']);
    Route::get('/users/owner/dashboard-summary', [PropertyController::class, 'dashboardSummary']);
    Route::get('properties/owners', [RentDeedController::class, 'owners']);
    Route::get('properties/tenants', [RentDeedController::class, 'tenants']);

    // Profile
    Route::get('profile', [ProfileController::class, 'show']);
    Route::post('profile', [ProfileController::class, 'storeOrUpdate']);
    Route::post('profile/payment-settings/stripe-connect/link', [ProfileController::class, 'createStripeConnectAccountLink']);
    Route::post('profile/payment-settings/stripe-connect/refresh', [ProfileController::class, 'refreshStripeConnectStatus']);

    // Users & Roles
    Route::resources(['users' => UserController::class]);
    Route::get('properties/rent-deeds', [RentDeedController::class, 'index']);

    // Route::get('properties/rent-deeds/{rentDeed}', [RentDeedController::class, 'show']);
    Route::post('properties/rent-deeds', [RentDeedController::class, 'store']);
    Route::post('properties/rent-deeds/{rentDeed}', [RentDeedController::class, 'update']);
    Route::put('properties/rent-deeds/{rentDeed}', [RentDeedController::class, 'update']);
    Route::delete('properties/rent-deeds/{rentDeed}', [RentDeedController::class, 'destroy']);

    Route::get('properties/commission-settings', [PropertyCommissionController::class, 'index']);
    // Properties
    Route::resources(['properties' => PropertyController::class]);
    Route::put('properties/{property}/commission-settings', [PropertyCommissionController::class, 'update']);
    Route::post('properties/{property}/assign-tenants', [PropertyController::class, 'assign']);
    Route::post('properties/{property}/assign-manager', [PropertyController::class, 'assignManager']);

    Route::get('properties/{property}/tenants', [PropertyController::class, 'tenants']);

    Route::get('/rent/saved-card/{tenancy}', [RentSubscriptionController::class, 'getSavedCard']);
    Route::post('/rent/subscribe', [RentSubscriptionController::class, 'subscribe']);
    Route::get('/rent-history', [RentHistoryController::class, 'index']);
    Route::get('/rents/overdue/{tenancyId}', [RentSubscriptionController::class, 'overdueRents']);
    Route::post('rents/pay-overdue', [RentSubscriptionController::class, 'payOverdues']);
    Route::post('rent/create-subscription-after-payment', [RentSubscriptionController::class, 'createSubscriptionAfterPayment']);
    Route::post('rent/activate-autopay', [RentSubscriptionController::class, 'activateAutoPay']);
    Route::post('/tenancy/{tenancy}/cancel-subscription', [RentSubscriptionController::class, 'cancelSubscription']);
    Route::post('/security-deposit/manual', [TenantController::class, 'requestManualSecurityDeposit']);
    Route::post('/security-deposit/stripe', [RentSubscriptionController::class, 'paySecurityDeposit']);

    Route::post(
        '/users/owner/security-deposit-approvals',
        [PropertyController::class, 'pendingApprovals']
    );

    Route::post(
        '/users/owner/security-deposit-approve',
        [PropertyController::class, 'approveManual']
    );

    Route::get('tenants/search', [TenantController::class, 'search']);
    Route::post('/send-email-otp', [ProfileController::class, 'sendEmailOtp']);
    Route::post('/verify-email-otp', [ProfileController::class, 'verifyEmailOtp']);

    Route::post('rent/rent-approval-request', [TenantController::class, 'requestManualRentDeposit']);
    Route::post(
        'users/owner/rent-approvals',
        [PropertyController::class, 'pendingRentApprovals']
    );

    Route::post(
        'users/owner/rent-approve',
        [PropertyController::class, 'approveRentManual']
    );

    Route::get('rent/reports/revenue', [ReportController::class, 'revenueReport']);
    Route::get('invoices', [InvoiceController::class, 'index']);
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);
    Route::get('invoices/{invoice}/download', [InvoiceController::class, 'download']);
    Route::post('invoices/{invoice}/resend', [InvoiceController::class, 'resend']);

    Route::prefix('v1/maintenance')->group(function () {
        Route::post('requests', [MaintenanceRequestController::class, 'store']);
        Route::get('requests/my', [MaintenanceRequestController::class, 'myRequests']);
        Route::get('summary', [MaintenanceRequestController::class, 'summary']);
        Route::get('requests', [MaintenanceRequestController::class, 'index']);
        Route::get('requests/{maintenanceRequest}', [MaintenanceRequestController::class, 'show']);
        Route::post('requests/{maintenanceRequest}/comment', [MaintenanceRequestController::class, 'addComment']);
        Route::post('requests/{maintenanceRequest}/approve', [MaintenanceRequestController::class, 'approve']);
        Route::post('requests/{maintenanceRequest}/reject', [MaintenanceRequestController::class, 'reject']);
        Route::post('requests/{maintenanceRequest}/status', [MaintenanceRequestController::class, 'updateStatus']);
        Route::post('requests/{maintenanceRequest}/assign', [MaintenanceRequestController::class, 'assign']);
        Route::get(
            'requests/{maintenanceRequest}/attachments/{attachment}',
            [MaintenanceRequestController::class, 'downloadAttachment']
        );
    });

    Route::prefix('v1/chat')->group(function () {
        Route::get('context', [ChatController::class, 'context']);
        Route::get('unread-count', [ChatController::class, 'unreadCount']);
        Route::post('presence/online', [ChatController::class, 'presenceOnline']);
        Route::post('presence/offline', [ChatController::class, 'presenceOffline']);
        Route::get('chats', [ChatController::class, 'index']);
        Route::post('chats/private', [ChatController::class, 'createPrivate']);
        Route::post('chats/group', [ChatController::class, 'createGroup']);
        Route::get('chats/{chat}', [ChatController::class, 'show']);
        Route::delete('chats/{chat}', [ChatController::class, 'deleteGroup']);
        Route::get('chats/{chat}/messages', [ChatController::class, 'messages']);
        Route::delete('chats/{chat}/participants/{user}', [ChatController::class, 'removeParticipant']);
        Route::post('chats/{chat}/messages', [ChatController::class, 'sendMessage']);
        Route::post('chats/{chat}/read', [ChatController::class, 'markRead']);
        Route::post('chats/{chat}/typing', [ChatController::class, 'typing']);
        Route::get('chats/{chat}/attachments/{attachment}', [ChatController::class, 'downloadAttachment']);
    });
});
