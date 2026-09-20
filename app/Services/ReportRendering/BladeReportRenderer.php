<?php

declare(strict_types=1);

namespace App\Services\ReportRendering;

use App\Domain\Report\HppReportDraft;
use App\Domain\Report\InternalReportDraft;
use Illuminate\Support\Facades\View;

/**
 * Renders the two F6 draft documents from their domain aggregates into
 * Blade templates. Pure string rendering: no database, queue, storage, or
 * network access happens here. The view factory is injectable so unit and
 * feature tests can drive it deterministically.
 */
final class BladeReportRenderer
{
    public const HPP_VIEW = 'reports.hpp';

    public const INTERNAL_VIEW = 'reports.internal';

    /** @var callable(string, array<string, mixed>): string */
    private $viewFactory;

    /** @param callable(string, array<string, mixed>): string $viewFactory */
    private function __construct(
        callable $viewFactory,
    ) {
        $this->viewFactory = $viewFactory;
    }

    public static function make(): self
    {
        // View::make(), not the view() helper: the helper's first parameter
        // is constrained to Larastan's view-string pseudo-type, which a
        // plain `string $view` closure parameter can never satisfy.
        return new self(
            static fn (string $view, array $data): string => View::make($view, $data)->render(),
        );
    }

    /** @param callable(string, array<string, mixed>): string $viewFactory */
    public static function withViewFactory(callable $viewFactory): self
    {
        return new self($viewFactory);
    }

    public function renderHpp(HppReportDraft $draft): string
    {
        return ($this->viewFactory)(self::HPP_VIEW, $draft->toViewData());
    }

    public function renderInternal(InternalReportDraft $draft): string
    {
        return ($this->viewFactory)(self::INTERNAL_VIEW, $draft->toViewData());
    }
}
