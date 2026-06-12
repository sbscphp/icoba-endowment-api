<?php

use App\Http\Controllers\v1\Admin\AuditTrail\AuditTrailController;
use App\Http\Controllers\v1\Admin\Auth\AdminLoginController;
use App\Http\Controllers\v1\Admin\Auth\PasswordController as AdminPasswordController;
use App\Http\Controllers\v1\Admin\Campaign\CampaignController;
use App\Http\Controllers\v1\Admin\Campaign\CampaignUpdateReportController;
use App\Http\Controllers\v1\Admin\ContentManagement\ContentPageController;
use App\Http\Controllers\v1\Admin\ContentManagement\HeroSlideController;
use App\Http\Controllers\v1\Admin\CertificateTemplate\CertificateTemplateController;
use App\Http\Controllers\v1\Admin\ContactSubmission\ContactSubmissionController;
use App\Http\Controllers\v1\Admin\Dashboard\DashboardController;
use App\Http\Controllers\v1\Admin\EmailCampaign\EmailCampaignController;
use App\Http\Controllers\v1\Admin\IssuedCertificate\IssuedCertificateController;
use App\Http\Controllers\v1\Admin\Media\ImageUploadController;
use App\Http\Controllers\v1\Admin\Notification\NotificationController;
use App\Http\Controllers\v1\Admin\Pledge\PledgeController;
use App\Http\Controllers\v1\Admin\Reconciliation\FcmbImportController;
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
        Route::post('refresh', [AdminLoginController::class, 'refresh'])->middleware('throttle:admin-token-refresh');
        Route::middleware('auth:sanctum')->post('logout', [AdminLoginController::class, 'logout']);
    });

    Route::get('audit-trails', [AuditTrailController::class, 'index'])
        ->middleware(['auth:sanctum', 'permission:audit_trail.read']);

    Route::middleware('auth:sanctum')->prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::post('/read-all', [NotificationController::class, 'markAllRead']);
        Route::get('/{id}', [NotificationController::class, 'show']);
        Route::patch('/{id}/read', [NotificationController::class, 'markRead']);
        Route::patch('/{id}/unread', [NotificationController::class, 'markUnread']);
        Route::delete('/{id}/dismiss', [NotificationController::class, 'dismiss']);
    });

    Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
        Route::get('/users', fn () => 'admin only');
    });

    Route::middleware('auth:sanctum')->prefix('settings')->group(function () {
        Route::get('/profile', [SettingsController::class, 'profile']);
        Route::patch('/profile', [SettingsController::class, 'updateProfile']);
        Route::patch('/2fa', [SettingsController::class, 'toggleTwoFactor']);
        Route::post('/2fa', [SettingsController::class, 'toggleTwoFactor']);
        Route::patch('/password', [SettingsController::class, 'changePassword']);
        Route::post('/password', [SettingsController::class, 'changePassword']);
        Route::patch('/notifications', [SettingsController::class, 'updateNotificationPreferences']);
        Route::post('/notifications', [SettingsController::class, 'updateNotificationPreferences']);
    });

    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('uploads/image', [ImageUploadController::class, 'store']);

        Route::get('permissions', [UserManagementController::class, 'permissionList'])
            ->middleware(['permission:roles.read']);

        Route::prefix('roles')->group(function () {
            Route::get('/dropdown/{status?}', [UserManagementController::class, 'roleDropdown'])
                ->where('status', 'active|inactive|all')
                ->middleware(['permission:roles.read']);
            Route::get('/with-permissions', [UserManagementController::class, 'rolesWithPermissions'])
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
            Route::delete('/{adminId}', [UserManagementController::class, 'deleteAdmin'])
                ->middleware(['permission:admins.delete']);
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

        Route::prefix('issued-certificates')->group(function () {
            Route::get('/stats', [IssuedCertificateController::class, 'stats'])
                ->middleware(['permission:issued_certificates.read']);
            Route::get('/', [IssuedCertificateController::class, 'index'])
                ->middleware(['permission:issued_certificates.read']);
            Route::get('/{recognitionId}/preview', [IssuedCertificateController::class, 'preview'])
                ->middleware(['permission:issued_certificates.read']);
            Route::patch('/{recognitionId}/revoke', [IssuedCertificateController::class, 'revoke'])
                ->middleware(['permission:issued_certificates.revoke']);
            Route::post('/{recognitionId}/reissue', [IssuedCertificateController::class, 'reissue'])
                ->middleware(['permission:issued_certificates.issue']);
            Route::get('/{recognitionId}', [IssuedCertificateController::class, 'show'])
                ->middleware(['permission:issued_certificates.read']);
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
            Route::get('/{templateId}/preview', [CertificateTemplateController::class, 'preview'])
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
                ->where('status', 'active|paused|completed|deactivated|all')
                ->middleware(['permission:campaigns.read']); // draft is not visible to admin currently
            Route::get('/stats', [CampaignController::class, 'stats'])
                ->middleware(['permission:campaigns.read']);
            Route::get('/', [CampaignController::class, 'index'])
                ->middleware(['permission:campaigns.read']);
            Route::post('/', [CampaignController::class, 'store'])
                ->middleware(['permission:campaigns.create']);
            Route::get('/{campaignId}', [CampaignController::class, 'show'])
                ->middleware(['permission:campaigns.read']);
            Route::get('/{campaignId}/donors', [CampaignController::class, 'donors'])
                ->middleware(['permission:campaigns.read']);
            Route::get('/{campaignId}/pledges', [CampaignController::class, 'pledges'])
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

            Route::prefix('/{campaignId}/reports')->group(function () {
                Route::get('/', [CampaignUpdateReportController::class, 'index'])
                    ->middleware(['permission:campaigns.read']);
                Route::post('/', [CampaignUpdateReportController::class, 'store'])
                    ->middleware(['permission:campaigns.create']);
                Route::get('/{reportId}', [CampaignUpdateReportController::class, 'show'])
                    ->middleware(['permission:campaigns.read']);
                Route::patch('/{reportId}', [CampaignUpdateReportController::class, 'update'])
                    ->middleware(['permission:campaigns.update']);
                Route::patch('/{reportId}/toggle-status', [CampaignUpdateReportController::class, 'toggleStatus'])
                    ->middleware(['permission:campaigns.update']);
                Route::delete('/{reportId}', [CampaignUpdateReportController::class, 'destroy'])
                    ->middleware(['permission:campaigns.delete']);
            });
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

        Route::prefix('reconciliation')->group(function () {
            Route::get('stats', [ReconciliationController::class, 'stats'])
                ->middleware(['permission:reconciliation.read']);
            Route::get('queue', [ReconciliationController::class, 'queue'])
                ->middleware(['permission:reconciliation.read']);
            Route::post('queue', [ReconciliationController::class, 'store'])
                ->middleware(['permission:reconciliation.update']);
            Route::get('queue/{uuid}', [ReconciliationController::class, 'show'])
                ->whereUuid('uuid')
                ->middleware(['permission:reconciliation.read']);
            Route::get('donors/search', [ReconciliationController::class, 'donorSearch'])
                ->middleware(['permission:reconciliation.read']);
            Route::get('pledges/search', [ReconciliationController::class, 'pledgeSearch'])
                ->middleware(['permission:reconciliation.read']);
            Route::get('bank-accounts', [ReconciliationController::class, 'bankAccounts'])
                ->middleware(['permission:reconciliation.read']);
            Route::get('queue/{uuid}/tier-preview', [ReconciliationController::class, 'tierPreview'])
                ->whereUuid('uuid')
                ->middleware(['permission:reconciliation.read']);
            Route::patch('queue/{uuid}/bank', [ReconciliationController::class, 'updateBank'])
                ->whereUuid('uuid')
                ->middleware(['permission:reconciliation.update']);
            Route::post('queue/{uuid}/complete', [ReconciliationController::class, 'complete'])
                ->whereUuid('uuid')
                ->middleware(['permission:reconciliation.update']);
            Route::post('fcmb/import', [FcmbImportController::class, 'store'])
                ->middleware(['permission:reconciliation.update']);
            Route::post('link-donation-to-pledge', [ReconciliationController::class, 'linkDonationToPledge'])
                ->middleware(['permission:reconciliation.update']);
        });

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

        Route::prefix('content-management')->group(function () {
            Route::get('/pages', [ContentPageController::class, 'index'])
                ->middleware(['permission:content_management.read']);

            Route::prefix('hero-slides')->group(function () {
                Route::get('/', [HeroSlideController::class, 'show'])
                    ->middleware(['permission:content_management.read']);
                Route::post('/', [HeroSlideController::class, 'store'])
                    ->middleware(['permission:content_management.create']);
                Route::patch('/', [HeroSlideController::class, 'update'])
                    ->middleware(['permission:content_management.update']);
                Route::get('/{slideId}', [HeroSlideController::class, 'show'])
                    ->middleware(['permission:content_management.read']);
                Route::patch('/{slideId}', [HeroSlideController::class, 'update'])
                    ->middleware(['permission:content_management.update']);
                Route::patch('/{slideId}/toggle-status', [HeroSlideController::class, 'toggleStatus'])
                    ->middleware(['permission:content_management.update']);
                Route::delete('/{slideId}', [HeroSlideController::class, 'destroy'])
                    ->middleware(['permission:content_management.delete']);
            });
        });

        Route::prefix('contact-submissions')->group(function () {
            Route::get('/stats', [ContactSubmissionController::class, 'stats'])
                ->middleware(['permission:contact_submissions.read']);
            Route::get('/', [ContactSubmissionController::class, 'index'])
                ->middleware(['permission:contact_submissions.read']);
            Route::get('/{submissionUuid}', [ContactSubmissionController::class, 'show'])
                ->middleware(['permission:contact_submissions.read']);
            Route::patch('/{submissionUuid}/status', [ContactSubmissionController::class, 'updateStatus'])
                ->middleware(['permission:contact_submissions.update']);
        });

        Route::prefix('email-campaigns')->group(function () {
            Route::get('/dropdown/{status?}', [EmailCampaignController::class, 'dropdown'])
                ->where('status', 'draft|queued|sent|partially_sent|failed|all');
                // ->middleware(['permission:email_campaigns.read']);
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
