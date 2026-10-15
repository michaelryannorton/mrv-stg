import { PRODUCTION_PREVIEW_NAV } from '@/components/mrv/navigation';
import PublicLayout, { type Season } from '@/layouts/public-layout';
import { Head } from '@inertiajs/react';

interface ComponentsProps {
    /** Production's rendered page content, one block per page (port/phase-1/reference/sheet-body.html). */
    html: string;
    season?: Season;
}

/**
 * Local environment only (routes/web.php, `port.components`): every component of the production site on one
 * page, compared element by element with the same markup under production's own stylesheet
 * (port/phase-1/tools/compare.mjs). Not part of the public site.
 */
export default function Components({ html, season }: ComponentsProps) {
    return (
        <PublicLayout activeId="home" season={season} pages={PRODUCTION_PREVIEW_NAV} account={false}>
            <Head title="Component sheet" />
            <div id="sheet" dangerouslySetInnerHTML={{ __html: html }} />
        </PublicLayout>
    );
}
