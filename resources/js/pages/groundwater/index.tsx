import MrvChart from '@/components/mrv/chart';
import { DirectoryHero, mapBoxStyle } from '@/components/mrv/directory-hero';
import { AreaButtons, ChangesTable, StatusLegend } from '@/components/mrv/groundwater';
import { WellMap } from '@/components/mrv/map';
import { resolveNav } from '@/components/mrv/navigation';
import { StatCards } from '@/components/mrv/stat-card';
import PublicLayout from '@/layouts/public-layout';
import {
    DEFAULT_AREA,
    drawnWells,
    fetchReadings,
    fetchSummary,
    gwStats,
    mapNote,
    rankedWells,
    startWell,
    STATUS_ORDER,
    visibleWells,
    wellChart,
    wellFacts,
    wellSub,
    type Summary,
    type Well,
    type WellStatus,
} from '@/lib/mrv/groundwater';
import { Head } from '@inertiajs/react';
import { type ChartConfiguration } from 'chart.js';
import { useEffect, useMemo, useRef, useState } from 'react';

// Groundwater (production: groundwater.html with js/groundwater.js): a map of monitoring wells colored by ten-year change,
// the record of one well, and a table of the largest changes.

const DESCRIPTION =
    "Depth to groundwater in monitoring wells around the Mojave River Valley, with how each well's water level has changed over about ten years.";

type Load = { status: 'loading' } | { status: 'error'; message: string } | { status: 'ready'; summary: Summary };
interface Selection {
    siteId: string;
    seq: number;
    /** Chosen by the visitor (the page scrolls to the record), not the well it opens on. */
    scroll: boolean;
}
interface Detail {
    siteId: string;
    note: string;
    config: ChartConfiguration | null;
}

export default function GroundwaterIndex({ preview = false }: { preview?: boolean }) {
    const { pages, account } = resolveNav(preview);
    const [load, setLoad] = useState<Load>({ status: 'loading' });
    const [area, setArea] = useState(DEFAULT_AREA);
    const [active, setActive] = useState<Set<WellStatus>>(new Set(STATUS_ORDER));
    const [selection, setSelection] = useState<Selection | null>(null);
    const [detail, setDetail] = useState<Detail | null>(null);
    const [fitKey, setFitKey] = useState(0);
    const wellSection = useRef<HTMLElement>(null);
    const seq = useRef(0);

    useEffect(() => {
        let live = true;
        fetchSummary().then(
            (summary) => {
                if (!live) return;
                if (!summary.wells || !summary.wells.length)
                    return setLoad({ status: 'error', message: 'Groundwater data has not been loaded yet.' });
                setLoad({ status: 'ready', summary });
            },
            (err: Error) => {
                console.error('Groundwater summary fetch failed:', err);
                if (live) setLoad({ status: 'error', message: 'Groundwater data could not be loaded right now.' });
            },
        );

        return () => {
            live = false;
        };
    }, []);

    const wells: Well[] = load.status === 'ready' ? load.summary.wells : [];
    const visible = useMemo(() => visibleWells(wells, area), [wells, area]);
    const drawn = useMemo(() => drawnWells(wells, area, active), [wells, area, active]);
    const ranked = useMemo(() => rankedWells(wells, area), [wells, area]);
    const stats = useMemo(() => (load.status === 'ready' ? gwStats(wells, area, load.summary.totals) : null), [load, wells, area]);

    const choose = (siteId: string, scroll: boolean) => setSelection({ siteId, seq: ++seq.current, scroll });

    // The page opens on a well, and moves to the new area's best well when the chosen one is not in it.
    useEffect(() => {
        if (load.status !== 'ready') return;
        if (selection && visible.some((w) => w.site_id === selection.siteId)) return;
        const start = startWell(visible);
        if (start) choose(start.site_id, false);
        else if (selection) setSelection(null);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [load.status, area]);

    // Read the chosen well's record.
    useEffect(() => {
        if (!selection) return;
        const { siteId } = selection;
        let live = true;
        setDetail((prev) => ({ siteId, note: 'Loading readings…', config: prev?.config ?? null }));
        if (selection.scroll) wellSection.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        const well = wells.find((w) => w.site_id === siteId) as Well;
        fetchReadings(siteId).then(
            (rows) => {
                if (!live) return;
                const { config, note } = wellChart(well, rows);
                setDetail({ siteId, note, config: config as unknown as ChartConfiguration | null });
            },
            (err: Error) => {
                console.error('Groundwater readings fetch failed:', err);
                if (live)
                    setDetail((prev) => ({ siteId, note: "This well's readings could not be loaded right now.", config: prev?.config ?? null }));
            },
        );

        return () => {
            live = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selection?.seq]);

    const toggleStatus = (status: WellStatus) =>
        setActive((current) => {
            const next = new Set(current);
            if (next.has(status)) next.delete(status);
            else next.add(status);

            return next;
        });

    const changeArea = (next: string) => {
        setArea(next);
        setFitKey((k) => k + 1);
    };

    const shownWell = detail ? wells.find((w) => w.site_id === detail.siteId) : undefined;
    const ready = load.status === 'ready';

    return (
        <PublicLayout activeId="groundwater" pages={pages} account={account}>
            <Head title="Groundwater — Mojave River Valley">
                <meta head-key="description" name="description" content={DESCRIPTION} />
            </Head>

            <DirectoryHero eyebrow="Groundwater" title="What's happening to the water under the valley">
                Much of the High Desert's water comes from wells. These are the wells that the U.S. Geological Survey and its partners measure, with
                decades of readings, and what those readings show.
            </DirectoryHero>

            <section>
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>How to Read This</h2>
                    </div>
                    <p style={{ maxWidth: '720px' }}>
                        Each reading is <strong>depth to water</strong>: how far below the ground surface the water stood when someone lowered a tape
                        into the well. When that number gets <strong>bigger</strong>, the water table has <strong>fallen</strong>. Wells are measured
                        in person, usually once or twice a year, so these are snapshots rather than a continuous record, and levels move with the
                        seasons and with pumping nearby. A change of 3 feet or less over ten years is called steady here because that is within normal
                        seasonal and measurement variation. In a few wells, mostly in the San Bernardino Valley, the water has at times stood{' '}
                        <strong>above</strong> the ground (a flowing artesian well); those readings are shown as feet above ground.
                    </p>
                    <p className="card-note" style={{ maxWidth: '720px', marginTop: '8px' }}>
                        The wells shown are the ones within 35 miles of Victorville that are still being measured and have at least 20 years of
                        record. About half are in the desert basins of the High Desert. The rest are south of Cajon Pass in the San Bernardino Valley,
                        which sits over a different aquifer system, so the two are shown separately; use the Area buttons to switch. Readings taken
                        while a pump was running, or in a dry or blocked well, are left out of every figure and chart on this page.
                    </p>
                </div>
            </section>

            <section className="alt">
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>Wells and Their Ten-Year Change</h2>
                        <p id="gw-summary-note">{stats?.note ?? ''}</p>
                    </div>
                    <div className={load.status === 'error' ? 'error-panel' : 'loading-panel'} id="gw-loading" hidden={ready}>
                        {load.status === 'error' ? load.message : 'Loading wells…'}
                    </div>
                    <div id="gw-body" hidden={!ready}>
                        {/* Production's page holds this frame, hidden, from the start; the wells fill it once they arrive. */}
                        <div className="control-row">
                            <span className="control-row-label">Area:</span>
                            <div id="gw-area-controls" className="map-controls">
                                {ready ? <AreaButtons wells={wells} area={area} onChange={changeArea} /> : null}
                            </div>
                        </div>
                        <div className="grid-stats grid" id="gw-stats" style={{ margin: '12px 0 16px' }}>
                            {stats ? <StatCards cards={stats.cards} /> : null}
                        </div>
                        <div id="gw-legend" className="map-legend">
                            {ready ? <StatusLegend wells={wells} area={area} active={active} onToggle={toggleStatus} /> : null}
                        </div>
                        {ready ? (
                            <WellMap
                                id="gw-map"
                                style={mapBoxStyle(520)}
                                wells={wells}
                                drawn={drawn}
                                fitWells={visible}
                                fitKey={fitKey}
                                selectedId={selection?.siteId ?? null}
                                onSelect={(siteId) => choose(siteId, true)}
                            />
                        ) : (
                            <div id="gw-map" style={mapBoxStyle(520)}></div>
                        )}
                        <p className="card-note" id="gw-map-note" style={{ marginTop: '8px' }}>
                            {ready ? mapNote(drawn.length, visible.length) : ''}
                        </p>
                    </div>
                </div>
            </section>

            <section id="gw-well-section" ref={wellSection} hidden={!detail}>
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2 id="gw-well-title">{shownWell ? `Well ${shownWell.name}` : 'A Well'}</h2>
                        <p id="gw-well-sub">{shownWell ? wellSub(shownWell) : ''}</p>
                    </div>
                    <div className="grid-stats grid" id="gw-well-stats" style={{ marginBottom: '16px' }}>
                        {shownWell ? <StatCards cards={wellFacts(shownWell)} /> : null}
                    </div>
                    <div className="card chart-card">
                        <div className="chart-tall">
                            <MrvChart id="chart-gw" config={detail?.config ?? null} style="stock" />
                        </div>
                    </div>
                    <p className="card-note" id="gw-well-note" style={{ maxWidth: '720px', marginTop: '10px' }}>
                        {detail?.note ?? ''}
                    </p>
                </div>
            </section>

            <section className="alt" id="gw-table-section" hidden={!ranked.length}>
                <div className="mrv-container">
                    <div className="section-heading">
                        <h2>Largest Changes</h2>
                        <p>The wells whose water level changed most over about ten years. Select a row to see that well.</p>
                    </div>
                    <div className="chart-scroll" id="gw-table">
                        {ranked.length ? <ChangesTable wells={ranked} onSelect={(siteId) => choose(siteId, true)} /> : null}
                    </div>
                    <p className="card-note" style={{ maxWidth: '720px', marginTop: '14px' }}>
                        Source: <a href="https://waterdata.usgs.gov/">U.S. Geological Survey</a> (National Water Information System), groundwater
                        field measurements. Some wells are measured by partner agencies and reported through the USGS.
                    </p>
                </div>
            </section>
        </PublicLayout>
    );
}
