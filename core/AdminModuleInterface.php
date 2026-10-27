<?php
declare(strict_types=1);

namespace AgeCMS;

/**
 * A module with its own pages inside the admin panel (declared as "admin" in the signed manifest, see ModuleAdmin).
 * Called only for a verified module, a signed-in user who holds the page's permission and, for POST, a valid admin CSRF token.
 */
interface AdminModuleInterface extends ModuleInterface
{
    /**
     * $request->path is the whole address ("/admin/m/mailing/campaigns/3"), $request->cookies is always empty.
     * Forms must contain ag_admin_csrf_field(). An html answer is drawn inside the admin layout, a redirect may only
     * lead to a page of the same module, a cookie in the answer is refused.
     */
    public function handleAdmin(ModuleRequest $request, Actor $actor): ModuleResponse;
}
