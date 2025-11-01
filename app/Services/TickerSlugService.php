<?php

namespace App\Services;

use Illuminate\Support\Str;

class TickerSlugService
{
    /**
     * Generate a URL-safe slug from ticker + name.
     * Example: ticker=AAPL, name="Apple Inc." => "apple-inc"
     * We intentionally *do not* include ticker in the slug itself because the URL will include ticker before the slug.
     *
     * @param string|null $name
     * @return string|null
     */
    public function slugFromName(?string $name): ?string
    {
        if (empty($name)) {
            return null;
        }

        // Generate a slug from the company name, limit length to 80 chars
        $slug = Str::slug($name);
        return Str::limit($slug, 80, '');
    }
}
