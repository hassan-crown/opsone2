<?php

use App\Http\Controllers\Org\BranchController;
use App\Http\Controllers\Org\InviteAcceptController;
use App\Http\Controllers\Org\MemberController;
use App\Http\Controllers\Org\OwnerInviteController;
use App\Http\Controllers\Org\RoleController;
use App\Http\Controllers\Org\SetupController;
use App\Http\Controllers\Org\TeamController;
use Illuminate\Support\Facades\Route;

// Invitation acceptance: public, single-use links; the emailed code is the ownership proof (Q-17).
Route::name('org.')->group(function () {
    Route::get('owner/invite/{token}', [OwnerInviteController::class, 'show'])->name('owner-invite.show');
    Route::post('owner/invite/{token}/code', [OwnerInviteController::class, 'sendCode'])->middleware('throttle:10,1')->name('owner-invite.code');
    Route::post('owner/invite/{token}', [OwnerInviteController::class, 'accept'])->middleware('throttle:10,1')->name('owner-invite.accept');

    Route::get('invitations/{token}', [InviteAcceptController::class, 'show'])->name('invite.show');
    Route::post('invitations/{token}/code', [InviteAcceptController::class, 'sendCode'])->middleware('throttle:10,1')->name('invite.code');
    Route::post('invitations/{token}', [InviteAcceptController::class, 'accept'])->middleware('throttle:10,1')->name('invite.accept');
});

// Organization screens: every action is authorized server-side through the central Gate (Access::can).
Route::middleware(['auth', 'verified'])->prefix('org')->name('org.')->group(function () {
    Route::get('setup', [SetupController::class, 'index'])->name('setup');
    Route::get('setup/{step}', [SetupController::class, 'show'])->name('setup.step');
    Route::put('setup/org', [SetupController::class, 'saveOrg'])->name('setup.org');
    Route::post('setup/complete', [SetupController::class, 'complete'])->name('setup.complete');
    Route::post('setup/{step}/next', [SetupController::class, 'next'])->name('setup.next');
    Route::post('setup/{step}/skip', [SetupController::class, 'skip'])->name('setup.skip');

    Route::get('team', [TeamController::class, 'index'])->name('team.index');
    Route::get('team/invitations', [TeamController::class, 'invitations'])->name('team.invitations');
    Route::get('team/invite', [TeamController::class, 'create'])->name('team.create');
    Route::post('team/invite', [TeamController::class, 'store'])->middleware('throttle:20,1')->name('team.store');
    Route::post('team/invitations/{invitation}/renew', [TeamController::class, 'renew'])->middleware('throttle:20,1')->name('team.renew');
    Route::post('team/invitations/{invitation}/cancel', [TeamController::class, 'cancel'])->name('team.cancel');

    Route::get('team/members/{membership}', [MemberController::class, 'show'])->name('team.member');
    Route::put('team/members/{membership}/access', [MemberController::class, 'updateAccess'])->name('team.member.access');
    Route::get('team/members/{membership}/disable', [MemberController::class, 'confirmDisable'])->name('team.member.confirm-disable');
    Route::post('team/members/{membership}/disable', [MemberController::class, 'disable'])->name('team.member.disable');
    Route::post('team/members/{membership}/reactivate', [MemberController::class, 'reactivate'])->name('team.member.reactivate');

    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::get('roles/{template}', [RoleController::class, 'edit'])->name('roles.edit');
    Route::put('roles/{template}', [RoleController::class, 'update'])->name('roles.update');

    Route::get('branches', [BranchController::class, 'index'])->name('branches.index');
    Route::post('branches', [BranchController::class, 'store'])->name('branches.store');
    Route::put('branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
    Route::post('branches/{branch}/disable', [BranchController::class, 'disable'])->name('branches.disable');
    Route::post('branches/{branch}/reactivate', [BranchController::class, 'reactivate'])->name('branches.reactivate');
});
