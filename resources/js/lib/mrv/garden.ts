/**
 * The Garden & Grow page's rules (public_html/js/garden.js), ported in port phase 5: the nearest-city lookup, the frost
 * statistics cards, the depth color ramps, and the chart configurations. Golden tests run the legacy script over the saved
 * data (garden.test.tsx).
 */
import type { ChartConfiguration } from 'chart.js';
import { PALETTE } from './chart-theme';
import { type StatCardData } from './earthquakes';
import { computeFrostWindow, computeSoilNormals, computeSolarNormals, computeVpdNormals, dayOfYearToLabel, SOIL_DEPTHS } from './stats';

type Cfg = ChartConfiguration<any, any, any>; // eslint-disable-line @typescript-eslint/no-explicit-any

export function haversineMiles(lat1: number, lon1: number, lat2: number, lon2: number): number {
    const R = 3958.8;
    const toRad = (d: number) => (d * Math.PI) / 180;
    const dLat = toRad(lat2 - lat1);
    const dLon = toRad(lon2 - lon1);
    const a = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLon / 2) ** 2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

/** The named city nearest a point (cities without coordinates are skipped), or null. */
export function findNearestCity(
    lat: number,
    lon: number,
    cityNames: string[],
    getCity: (name: string) => { lat: number | null; lon: number | null } | undefined,
): { name: string; distanceMiles: number } | null {
    let nearest: string | null = null;
    let nearestDist = Infinity;
    for (const name of cityNames) {
        const city = getCity(name) as { lat: number | null; lon: number | null };
        if (city.lat == null || city.lon == null) continue;
        const d = haversineMiles(lat, lon, city.lat, city.lon);
        if (d < nearestDist) {
            nearestDist = d;
            nearest = name;
        }
    }
    return nearest ? { name: nearest, distanceMiles: nearestDist } : null;
}

export function frostCards(frost: ReturnType<typeof computeFrostWindow>): StatCardData[] {
    const growingDays =
        frost.lastSpringFrostDay != null && frost.firstFallFrostDay != null ? frost.firstFallFrostDay - frost.lastSpringFrostDay : null;

    return [
        {
            label: 'Typical Last Spring Frost',
            value: frost.lastSpringFrostDay != null ? dayOfYearToLabel(frost.lastSpringFrostDay) : '—',
            sub: frost.yearsWithSpringFrost
                ? `Based on ${frost.yearsWithSpringFrost} of ${frost.totalYears} years with a spring frost`
                : 'No spring frost recorded in this data',
        },
        {
            label: 'Typical First Fall Frost',
            value: frost.firstFallFrostDay != null ? dayOfYearToLabel(frost.firstFallFrostDay) : '—',
            sub: frost.yearsWithFallFrost
                ? `Based on ${frost.yearsWithFallFrost} of ${frost.totalYears} years with a fall frost`
                : 'No fall frost recorded in this data',
        },
        {
            label: 'Typical Frost-Free Growing Season',
            value: growingDays != null ? `${growingDays} days` : '—',
            sub: growingDays != null ? 'Last spring frost to first fall frost, averaged across years' : '',
        },
    ];
}

/** A sequential ramp by depth (shallow light, deep dark): depth is an ordered magnitude here, not a category. */
export function depthColors(baseHue: number): string[] {
    return SOIL_DEPTHS.map((_, i) => {
        const t = i / (SOIL_DEPTHS.length - 1);
        const l = Math.round(75 - t * 40); // 75% (shallow) to 35% (deep)
        return `hsl(${baseHue}, 50%, ${l}%)`;
    });
}

export const ET0_NOTE =
    'ET0 is "reference" evapotranspiration — a standardized well-watered grass reference, not your specific crop. Real crop water need = ET0 × a crop-specific coefficient (Kc), which varies by plant and growth stage and isn’t modeled here.';

type Solar = ReturnType<typeof computeSolarNormals>;
type Vpd = ReturnType<typeof computeVpdNormals>;
type Soil = ReturnType<typeof computeSoilNormals>;

export const et0Config = (solarNormals: Solar): Cfg =>
    ({
        type: 'bar',
        data: {
            labels: solarNormals.map((n) => n.monthName),
            datasets: [
                { label: 'Reference ET0 (in/day)', data: solarNormals.map((n) => n.avgEt0), backgroundColor: PALETTE.terracotta, borderRadius: 4 },
            ],
        },
        options: {
            responsive: true,
            scales: { y: { title: { display: true, text: 'inches/day' }, beginAtZero: true }, x: { grid: { display: false } } },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx: { parsed: { y: number | null } }) => {
                            const perDay = ctx.parsed.y;
                            if (perDay == null) return '—';
                            return `${perDay.toFixed(3)} in/day (about ${(perDay * 7).toFixed(2)} in/week)`;
                        },
                    },
                },
            },
        },
    }) as Cfg;

export const vpdConfig = (vpdNormals: Vpd): Cfg =>
    ({
        type: 'line',
        data: {
            labels: vpdNormals.map((n) => n.monthName),
            datasets: [
                {
                    label: 'Avg VPD (kPa)',
                    data: vpdNormals.map((n) => n.avgVpd),
                    borderColor: PALETTE.plum,
                    backgroundColor: PALETTE.plum,
                    tension: 0.3,
                    borderWidth: 2,
                },
            ],
        },
        options: {
            responsive: true,
            scales: { y: { title: { display: true, text: 'kPa' }, beginAtZero: true }, x: { grid: { display: false } } },
            plugins: { legend: { display: false } },
        },
    }) as Cfg;

function soilConfig(soilNormals: Soil, prefix: 'temp' | 'moist', hue: number, yAxis: object): Cfg {
    const colors = depthColors(hue);
    return {
        type: 'line',
        data: {
            labels: soilNormals.map((n) => n.monthName),
            datasets: SOIL_DEPTHS.map((d, i) => ({
                label: d.label,
                data: soilNormals.map((n) => (n as unknown as Record<string, unknown>)[`${prefix}_${d.key}`]),
                borderColor: colors[i],
                backgroundColor: colors[i],
                tension: 0.3,
                borderWidth: 2,
            })),
        },
        options: { responsive: true, scales: { y: yAxis, x: { grid: { display: false } } }, plugins: { legend: { position: 'top' } } },
    } as Cfg;
}

export const soilTempConfig = (soilNormals: Soil): Cfg => soilConfig(soilNormals, 'temp', 18, { title: { display: true, text: '°F' } }); // terracotta-family hue
export const soilMoistureConfig = (soilNormals: Soil): Cfg =>
    soilConfig(soilNormals, 'moist', 203, { title: { display: true, text: 'm³/m³' }, beginAtZero: true }); // sky-family hue
