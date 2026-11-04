<?php

use App\Operations\Upgrade\Expand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-launch security audit (M-3, L-9):
 * - `portal_blocked_at` tells «staff turned the workspace off» apart from «never invited»: a checkout code never reopens
 *   a blocked workspace.
 * - `portal_invite_version` is carried by the activation link; it moves on the first activation and when staff turn the
 *   workspace off, so older links stop opening the activation page.
 */
return new #[Expand] class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('portal_blocked_at')->nullable()->after('portal_login_enabled');
            $table->unsignedInteger('portal_invite_version')->default(1)->after('portal_session_version');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['portal_blocked_at', 'portal_invite_version']);
        });
    }
};
