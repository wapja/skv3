<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Settings\UpdateLocale;
use App\Enums\Locale;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The language toggle (§6.5): a guest's choice lives in the session, a user's on the account. */
final class LocaleController extends Controller
{
    public function __invoke(Request $request, UpdateLocale $updateLocale): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::enum(Locale::class)],
        ]);

        $locale = Locale::from((string) $validated['locale']);
        $user = $request->user();

        if ($user instanceof User) {
            $updateLocale->handle($user, $locale);
        } else {
            $request->session()->put('locale', $locale->value);
        }

        return back();
    }
}
