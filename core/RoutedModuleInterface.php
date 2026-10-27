<?php
declare(strict_types=1);

namespace AgeCMS;

/**
 * A module that serves its own addresses (declared as "routes" in the signed manifest).
 * Called only for verified modules, after the core has checked the request limits and, for POST, the form token.
 */
interface RoutedModuleInterface extends ModuleInterface
{
    public function handle(ModuleRequest $request): ModuleResponse;
}
