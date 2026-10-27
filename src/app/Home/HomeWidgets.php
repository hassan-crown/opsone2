<?php

namespace App\Home;

use App\Access\Access;
use App\Access\Permission;
use App\Models\User;

/**
 * Registry of work-center widgets. Later domains append their widgets here (or via `register()` from their
 * service provider) instead of editing the home page. Each widget carries a `home.widget.{key}.*` translation set.
 */
final class HomeWidgets
{
    /** @var list<HomeWidget> */
    private array $widgets;

    public function __construct()
    {
        $this->widgets = [
            new HomeWidget('my_tasks', HomeWidget::SECTION_TASKS, Permission::P24),
            new HomeWidget('department_inbox', HomeWidget::SECTION_TASKS, Permission::P22),
            new HomeWidget('orders_attention', HomeWidget::SECTION_ALERTS, Permission::P16),
        ];
    }

    public function register(HomeWidget $widget): void
    {
        $this->widgets[] = $widget;
    }

    /**
     * Widgets the user may see, grouped by section: `[section => [widget keys]]`.
     *
     * @return array{tasks: list<string>, alerts: list<string>}
     */
    public function visibleFor(User $user): array
    {
        $sections = ['tasks' => [], 'alerts' => []];

        foreach ($this->widgets as $widget) {
            if (! Access::can($user, $widget->permission)) {
                continue;
            }

            if ($widget->section === HomeWidget::SECTION_ALERTS) {
                $sections['alerts'][] = $widget->key;
            } else {
                $sections['tasks'][] = $widget->key;
            }
        }

        return $sections;
    }
}
