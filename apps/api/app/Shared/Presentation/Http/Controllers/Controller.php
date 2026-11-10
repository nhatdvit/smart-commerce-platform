<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Http\Controllers;

/**
 * Base controller.
 *
 * Intentionally almost empty: controllers must stay thin (CLAUDE.md section 3).
 * Input validation belongs in Form Requests, output shaping in API Resources,
 * and orchestration in application Actions. Nothing that looks like a business
 * rule or an Eloquent query chain belongs in a subclass of this.
 */
abstract class Controller {}
