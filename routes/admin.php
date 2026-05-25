<?php

use App\Http\Controllers\v1\Admin\AuditTrail\AuditTrailController;
use App\Http\Controllers\v1\Admin\Auth\AdminLoginController;
use App\Http\Controllers\v1\Admin\Auth\PasswordController as AdminPasswordController;
use App\Http\Controllers\v1\Admin\Campaign\CampaignController;
use App\Http\Controllers\v1\Admin\CertificateTemplate\CertificateTemplateController;
use App\Http\Controllers\v1\Admin\ContactSubmission\ContactSubmissionController;
use App\Http\Controllers\v1\Admin\Dashboard\DashboardController;
use App\Http\Controllers\v1\Admin\EmailCampaign\EmailCampaignController;
use App\Http\Controllers\v1\Admin\Notification\NotificationController;
use App\Http\Controllers\v1\Admin\Pledge\PledgeController;
use App\Http\Controllers\v1\Admin\Reconciliation\ReconciliationController;
use App\Http\Controllers\v1\Admin\Report\ReportController;
use App\Http\Controllers\v1\Admin\Settings\SettingsController;
use App\Http\Controllers\v1\Admin\TierConfiguration\TierConfigurationController;
use App\Http\Controllers\v1\Admin\Transaction\TransactionController;
use App\Http\Controllers\v1\Admin\UserManagement\UserManagementController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/admin')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('login', [AdminLoginController::class, 'login'])->middleware('throttle:admin-login');
        Route::post('login/verify-otp', [AdminLoginController::class, 'verifyOtp'])->middleware('throttle:admin-otp-verify');
        Route::post('login/resend-otp', [AdminLoginController::class, 'resendOtp'])->middleware('throttle:admin-otp-send');
        Route::post('forgot-password', [AdminPasswordController::class, 'forgotPassword'])->middleware('throttle:admin-otp-send');
        Route::post('forgot-password/resend', [AdminPasswordController::class, 'forgotPasswordResend'])->middleware('throttle:admin-otp-send');
        Route::post('forgot-password/verify', [AdminPasswordController::class, 'forgotPasswordVerify'])->middleware('throttle:admin-otp-verify');
        Route::post('reset-password', [AdminPasswordController::class, 'resetPassword'])->middleware('throttle:admin-otp-verify');
        Route::middleware('auth:sanctum')->post('logout', [AdminLoginController::class, 'logout']);
    });

    Route::get('audit-trails', [AuditTrailController::class, 'index'])
        ->middleware(['auth:sanctum', 'permission:audit_trail.read']);

    Route::prefix('notifications')->middleware(['auth:sanctum'])->group(function () {
        Route::middleware(['permission:notifications.read'])->group(function () {
            Route::get('/', [NotificationController::class, 'index']);
            Route::get('/{id}', [NotificationController::class, 'show']);
        });

        Route::middleware(['permission:notifications.update'])->group(function () {
            Route::post('/read-all', [NotificationController::class, 'markAllRead']);
            Route::patch('/{id}/read', [NotificationController::class, 'markRead']);
            Route::patch('/{id}/unread', [NotificationController::class, 'markUnread']);
            Route::delete('/{id}/dismiss', [NotificationController::class, 'dismiss']);
        });
    });

    Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
        Route::get('/users', fn () => 'admin only');
    });

    Route::prefix('settings')->middleware(['auth:sanctum'])->group(function () {
        Route::get('/profile', [SettingsController::class, 'profile']);
        Route::patch('/password', [SettingsController::class, 'changePassword']);
        Route::post('/password', [SettingsController::class, 'changePassword']);
        Route::patch('/notifications', [SettingsController::class, 'updateNotificationPreferences']);
        Route::post('/notifications', [SettingsController::class, 'updateNotificationPreferences']);
    });

    Route::middleware(['auth:sanctum'])->group(function () {
        Route::prefix('roles')->group(function () {
            Route::get('/dropdown/{status?}', [UserManagementController::class, 'roleDropdown'])
                ->where('status', 'active|inactive|all')
                ->middleware(['permission:roles.read']);
            Route::get('/stats', [UserManagementController::class, 'roleStats'])
                ->middleware(['permission:roles.read']);
            Route::post('/', [UserManagementController::class, 'createRole'])
                ->middleware(['permission:roles.create']);
            Route::get('/', [UserManagementController::class, 'roleList'])
                ->middleware(['permission:roles.read']);
            Route::get('/{roleId}', [UserManagementController::class, 'viewRole'])
                ->middleware(['permission:roles.read']);
            Route::patch('/{roleId}', [UserManagementController::class, 'updateRole'])
                ->middleware(['permission:roles.update']);
            Route::patch('/{roleId}/toggle-status', [UserManagementController::class, 'setRoleActiveStatus'])
                ->middleware(['permission:roles.update']);
            Route::delete('/{roleId}', [UserManagementController::class, 'deleteRole'])
                ->middleware(['permission:roles.delete']);
        });

        Route::prefix('admin-users')->group(function () {
            Route::get('/dropdown/{status?}', [UserManagementController::class, 'adminDropdown'])
                ->where('status', 'active|inactive|all')
                ->middleware(['permission:admins.read']);
            Route::get('/stats', [UserManagementController::class, 'adminStats'])
                ->middleware(['permission:admins.read']);
            Route::post('/', [UserManagementController::class, 'createAdmin'])
                ->middleware(['permission:admins.create']);
            Route::get('/', [UserManagementController::class, 'adminList'])
                ->middleware(['permission:admins.read']);
            Route::get('/{adminId}', [UserManagementController::class, 'viewAdmin'])
                ->middleware(['permission:admins.read']);
            Route::patch('/{adminId}', [UserManagementController::class, 'updateAdmin'])
                ->middleware(['permission:admins.update']);
            Route::patch('/{adminId}/toggle-status', [UserManagementController::class, 'setAdminActiveStatus'])
                ->middleware(['permission:admins.update']);
            Route::post('/{adminId}/resend-invite-link', [UserManagementController::class, 'resendAdminInviteLink'])
                ->middleware(['permission:admins.update']);
        });

        Route::prefix('tier-configurations')->group(function () {
            Route::get('/benefits', [TierConfigurationController::class, 'benefitOptions'])
                ->middleware(['permission:tier_configuration.read']);
            Route::get('/dropdown/{status?}', [TierConfigurationController::class, 'dropdown'])
                ->where('status', 'active|inactive|all')
                ->middleware(['permission:tier_configuration.read']);
            Route::get('/stats', [TierConfigurationController::class, 'stats'])
                ->middleware(['permission:tier_configuration.read']);
            Route::get('/', [TierConfigurationController::class, 'index'])
                ->middleware(['permission:tier_configuration.read']);
            Route::post('/', [TierConfigurationController::class, 'store'])
                ->middleware(['permission:tier_configuration.create']);
            Route::get('/{tierId}', [TierConfigurationController::class, 'show'])
                ->middleware(['permission:tier_configuration.read']);
            Route::patch('/{tierId}', [TierConfigurationController::class, 'update'])
                ->middleware(['permission:tier_configuration.update']);
            Route::patch('/{tierId}/toggle-status', [TierConfigurationController::class, 'toggleStatus'])
                ->middleware(['permission:tier_configuration.update']);
            Route::delete('/{tierId}', [TierConfigurationController::class, 'destroy'])
                ->middleware(['permission:tier_configuration.delete']);
        });

        Route::prefix('certificate-templates')->group(function () {
            Route::get('/dropdown/{status?}', [CertificateTemplateController::class, 'dropdown'])
                ->where('status', 'active|inactive|all')
                ->middleware(['permission:certificate_templates.read']);
            Route::get('/stats', [CertificateTemplateController::class, 'stats'])
                ->middleware(['permission:certificate_templates.read']);
            Route::get('/', [CertificateTemplateController::class, 'index'])
                ->middleware(['permission:certificate_templates.read']);
            Route::post('/', [CertificateTemplateController::class, 'store'])
                ->middleware(['permission:certificate_templates.create']);
            Route::get('/{templateId}', [CertificateTemplateController::class, 'show'])
                ->middleware(['permission:certificate_templates.read']);
            Route::patch('/{templateId}', [CertificateTemplateController::class, 'update'])
                ->middleware(['permission:certificate_templates.update']);
            Route::patch('/{templateId}/toggle-status', [CertificateTemplateController::class, 'toggleStatus'])
                ->middleware(['permission:certificate_templates.update']);
            Route::delete('/{templateId}', [CertificateTemplateController::class, 'destroy'])
                ->middleware(['permission:certificate_templates.delete']);
        });

        Route::prefix('campaigns')->group(function () {
            Route::get('/categories/options', [CampaignController::class, 'categoryOptions'])
                ->middleware(['permission:campaigns.read']);
            Route::get('/currencies/options', [CampaignController::class, 'currencyOptions'])
                ->middleware(['permission:campaigns.read']);
            Route::get('/applicable-options/{status?}', [CampaignController::class, 'applicableOptions'])
                ->where('status', 'active|inactive|all')
                ->middleware(['permission:campaigns.read']);
            Route::get('/dropdown/{status?}', [CampaignController::class, 'dropdown'])
                ->where('status', 'draft|active|paused|completed|deactivated|all')
                ->middleware(['permission:campaigns.read']);
            Route::get('/stats', [CampaignController::class, 'stats'])
                ->middleware(['permission:campaigns.read']);
            Route::get('/', [CampaignController::class, 'index'])
                ->middleware(['permission:campaigns.read']);
            Route::post('/', [CampaignController::class, 'store'])
                ->middleware(['permission:campaigns.create']);
            Route::get('/{campaignId}', [CampaignController::class, 'show'])
                ->middleware(['permission:campaigns.read']);
            Route::patch('/{campaignId}', [CampaignController::class, 'update'])
                ->middleware(['permission:campaigns.update']);
            Route::delete('/{campaignId}', [CampaignController::class, 'destroy'])
                ->middleware(['permission:campaigns.delete']);
            Route::get('/{campaignId}/status-logs', [CampaignController::class, 'statusLogs'])
                ->middleware(['permission:campaigns.read']);
            Route::get('/{campaignId}/report', [CampaignController::class, 'report'])
                ->middleware(['permission:campaigns.read']);
            Route::post('/{campaignId}/transition', [CampaignController::class, 'transition'])
                ->middleware(['permission:campaigns.publish']);
        });

        Route::prefix('transactions')->group(function () {
            Route::get('/stats', [TransactionController::class, 'stats'])
                ->middleware(['permission:transactions.read']);
            Route::get('/', [TransactionController::class, 'index'])
                ->middleware(['permission:transactions.read']);
            Route::get('/{transactionId}', [TransactionController::class, 'show'])
                ->middleware(['permission:transactions.read']);
        });

        Route::prefix('pledges')->group(function () {
            Route::get('/stats', [PledgeController::class, 'stats'])
                ->middleware(['permission:pledges.read']);
            Route::get('/', [PledgeController::class, 'index'])
                ->middleware(['permission:pledges.read']);
            Route::post('/', [PledgeController::class, 'store'])
                ->middleware(['permission:pledges.create']);
            Route::get('/{pledgeUuid}', [PledgeController::class, 'show'])
                ->middleware(['permission:pledges.read']);
        });

        Route::post('reconciliation/link-donation-to-pledge', [ReconciliationController::class, 'linkDonationToPledge'])
            ->middleware(['permission:reconciliation.update']);

        Route::prefix('reports')->group(function () {
            Route::get('/types', [ReportController::class, 'reportTypes'])
                ->middleware(['permission:reports.read']);
            Route::get('/generate', [ReportController::class, 'generate'])
                ->middleware(['permission:reports.read']);
        });

        Route::prefix('dashboard')->group(function () {
            Route::get('/overview', [DashboardController::class, 'overview'])
                ->middleware(['permission:dashboard.read']);
            Route::get('/campaign-contribution-trend', [DashboardController::class, 'campaignContributionTrend'])
                ->middleware(['permission:dashboard.read']);
            Route::get('/donation-by-user-type', [DashboardController::class, 'donationByUserType'])
                ->middleware(['permission:dashboard.read']);
            Route::get('/donation-by-donation-type', [DashboardController::class, 'donationByDonationType'])
                ->middleware(['permission:dashboard.read']);
            Route::get('/donation-by-contribution-tier', [DashboardController::class, 'donationByContributionTier'])
                ->middleware(['permission:dashboard.read']);
            Route::get('/campaigns/active', [DashboardController::class, 'activeCampaigns'])
                ->middleware(['permission:dashboard.read']);
        });

        Route::prefix('contact-submissions')->group(function () {
            Route::get('/', [ContactSubmissionController::class, 'index'])
                ->middleware(['permission:contact_submissions.read']);
            Route::get('/{submissionUuid}', [ContactSubmissionController::class, 'show'])
                ->middleware(['permission:contact_submissions.read']);
            Route::patch('/{submissionUuid}/status', [ContactSubmissionController::class, 'updateStatus'])
                ->middleware(['permission:contact_submissions.update']);
        });

        Route::prefix('email-campaigns')->group(function () {
            Route::get('/dropdown/{status?}', [EmailCampaignController::class, 'dropdown'])
                ->where('status', 'draft|queued|sent|partially_sent|failed|all')
                ->middleware(['permission:email_campaigns.read']);
            Route::get('/design-templates/options', [EmailCampaignController::class, 'designTemplateOptions'])
                ->middleware(['permission:email_campaigns.read']);
            Route::get('/audiences/options', [EmailCampaignController::class, 'audienceOptions'])
                ->middleware(['permission:email_campaigns.read']);
            Route::get('/', [EmailCampaignController::class, 'index'])
                ->middleware(['permission:email_campaigns.read']);
            Route::post('/', [EmailCampaignController::class, 'store'])
                ->middleware(['permission:email_campaigns.create']);
            Route::get('/{emailId}', [EmailCampaignController::class, 'show'])
                ->middleware(['permission:email_campaigns.read']);
            Route::patch('/{emailId}', [EmailCampaignController::class, 'update'])
                ->middleware(['permission:email_campaigns.update']);
            Route::delete('/{emailId}', [EmailCampaignController::class, 'destroy'])
                ->middleware(['permission:email_campaigns.delete']);
            Route::patch('/{emailId}/set-active', [EmailCampaignController::class, 'setActive'])
                ->middleware(['permission:email_campaigns.update']);
            Route::post('/{emailId}/send', [EmailCampaignController::class, 'send'])
                ->middleware(['permission:email_campaigns.send']);
        });
    });
});
