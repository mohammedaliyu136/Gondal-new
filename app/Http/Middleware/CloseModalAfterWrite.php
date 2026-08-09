<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the modal a write was submitted from.
 *
 * THE PROBLEM. Every modal in this application is a CSS `:target` modal — it is
 * open because the URL ends in `#modal-something`. A form inside one posts, the
 * controller redirects with `back()`, and the browser lands on the same screen
 * with the modal still open, looking as though nothing happened.
 *
 * The cause is in the HTTP specification rather than in the application: a
 * fragment is never sent to the server, so a redirect can never mention one, and
 * RFC 7231 §7.1.2 says that when a Location carries no fragment the client
 * INHERITS the one it already had. So `Location: /departments` resolves in the
 * browser to `/departments#modal-department`, `:target` matches again, and the
 * modal reopens over the success message.
 *
 * THE FIX. After a write, give the redirect an empty fragment. `/departments#`
 * matches no element, so `:target` finds nothing and the modal closes. A
 * controller that deliberately wants a modal open afterwards — "save and add
 * another" is the one that does — sets its own fragment, and an explicit
 * fragment is left alone.
 *
 * Done here rather than in thirty controllers because it is one behaviour, and
 * because the next modal somebody adds should not have to know about it.
 */
class CloseModalAfterWrite
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof RedirectResponse) {
            return $response;
        }

        // Only writes. A GET that redirects is navigation, and the fragment it
        // carries may be the whole point of the link.
        if ($request->isMethodSafe()) {
            return $response;
        }

        /*
         * Only submissions that came FROM a modal. Every modal form carries a
         * `_modal` marker naming itself; a sign-in or a sign-out has no fragment
         * to clear, and appending one to those would put a stray "#" in the
         * address bar of the most-visited screens for no reason.
         */
        if (! $request->filled('_modal')) {
            return $response;
        }

        $target = $response->getTargetUrl();

        // The controller asked for something specific; leave it be.
        if (str_contains($target, '#')) {
            return $response;
        }

        return $response->setTargetUrl($target.'#');
    }
}
