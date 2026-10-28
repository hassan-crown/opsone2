<?php

namespace App\Notifications;

use App\Access\Permission;

/**
 * Notification types (SRS-SHR-05/06). `mandatory` types always send the email copy (security or account
 * relevant); optional ones follow the user's preference. `permission` is what opening the link re-checks on the
 * target record — a link never carries access of its own. Domain phases add their types here.
 */
final class NotificationCatalogue
{
    /**
     * @var array<string, array{mandatory: bool, permission: Permission|null, title: string, body: string|null, route: string|null}>
     */
    public const DEFINITIONS = [
        'ownership.transfer_requested' => [
            'mandatory' => true,
            'permission' => null,
            'title' => 'notifications.type.ownership_transfer_requested.title',
            'body' => 'notifications.type.ownership_transfer_requested.body',
            'route' => 'ownership.show',
        ],
        'support.access_requested' => [
            'mandatory' => true,
            'permission' => Permission::P79,
            'title' => 'notifications.type.support_access_requested.title',
            'body' => 'notifications.type.support_access_requested.body',
            'route' => 'support-access.index',
        ],
        'work.assigned' => [
            'mandatory' => false,
            'permission' => null,
            'title' => 'notifications.type.work_assigned.title',
            'body' => null,
            'route' => null,
        ],
        'work.reminder' => [
            'mandatory' => false,
            'permission' => null,
            'title' => 'notifications.type.work_reminder.title',
            'body' => null,
            'route' => null,
        ],
        // P5: work and appointments; opening re-checks P-24 on the live task or appointment.
        'task.assigned' => [
            'mandatory' => false,
            'permission' => Permission::P24,
            'title' => 'notifications.type.task_assigned.title',
            'body' => 'notifications.type.task_assigned.body',
            'route' => null,
        ],
        'task.unassigned' => [
            'mandatory' => false,
            'permission' => Permission::P24,
            'title' => 'notifications.type.task_unassigned.title',
            'body' => 'notifications.type.task_unassigned.body',
            'route' => null,
        ],
        'appointment.confirmed' => [
            'mandatory' => false,
            'permission' => Permission::P24,
            'title' => 'notifications.type.appointment_confirmed.title',
            'body' => 'notifications.type.appointment_confirmed.body',
            'route' => null,
        ],
        'appointment.changed' => [
            'mandatory' => false,
            'permission' => Permission::P24,
            'title' => 'notifications.type.appointment_changed.title',
            'body' => 'notifications.type.appointment_changed.body',
            'route' => null,
        ],
        'appointment.cancelled' => [
            'mandatory' => false,
            'permission' => Permission::P24,
            'title' => 'notifications.type.appointment_cancelled.title',
            'body' => 'notifications.type.appointment_cancelled.body',
            'route' => null,
        ],
        'appointment.conflict' => [
            'mandatory' => false,
            'permission' => Permission::P24,
            'title' => 'notifications.type.appointment_conflict.title',
            'body' => 'notifications.type.appointment_conflict.body',
            'route' => null,
        ],
    ];

    /**
     * Types added at runtime by domain phases (and tests).
     *
     * @var array<string, array{mandatory: bool, permission: Permission|null, title: string, body: string|null, route: string|null}>
     */
    private static array $extra = [];

    /**
     * @param  array{mandatory: bool, permission: Permission|null, title: string, body: string|null, route: string|null}  $definition
     */
    public static function define(string $type, array $definition): void
    {
        self::$extra[$type] = $definition;
    }

    public static function flush(): void
    {
        self::$extra = [];
    }

    /**
     * @return array<string, array{mandatory: bool, permission: Permission|null, title: string, body: string|null, route: string|null}>
     */
    public static function all(): array
    {
        return self::DEFINITIONS + self::$extra;
    }

    /**
     * @return array{mandatory: bool, permission: Permission|null, title: string, body: string|null, route: string|null}|null
     */
    public static function get(string $type): ?array
    {
        return self::all()[$type] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function optionalTypes(): array
    {
        return array_keys(array_filter(self::all(), fn (array $d) => ! $d['mandatory']));
    }
}
