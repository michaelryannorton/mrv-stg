import { fmtDate, modelRows, type ClimateData } from '@/lib/mrv/climate';

/** The model-by-model table: the same comparison for each of the seven models, so the disagreement behind the middle value shows. */
export function ModelTable({ data }: { data: ClimateData }) {
    return (
        <table className="data-table">
            <thead>
                <tr>
                    <th>Model</th>
                    <th>Average high</th>
                    <th>Days at or above 100°F a year</th>
                    <th>Yearly precipitation</th>
                </tr>
            </thead>
            <tbody>
                {modelRows(data).map((r) => (
                    <tr key={r.name}>
                        <td>{r.name}</td>
                        <td>{r.high}</td>
                        <td>{r.days}</td>
                        <td>{r.precip}</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

/** The source and citation paragraph under the table (production writes it with innerHTML; the first date is the day the download began). */
export function SourceNote({ data }: { data: ClimateData }) {
    const first = data.queried_from ? fmtDate(data.queried_from) : null;

    return (
        <>
            Source: <a href="https://open-meteo.com/en/docs/climate-api">Open-Meteo Climate API</a> (weather data by{' '}
            <a href="https://open-meteo.com/">Open-Meteo.com</a>), CMIP6 high-resolution models, licensed CC BY 4.0, adjusted to the recorded weather
            at each location (ERA5-Land). Citation terms for the underlying model output:{' '}
            <a href="https://pcmdi.llnl.gov/CMIP6/TermsOfUse">CMIP6 terms of use</a>. These figures come from the models as we downloaded them
            {first ? ` starting ${first}` : ''}; we re-run the download periodically and keep every version, so changes in what the models say can be
            compared later. The "→" in the table runs from the 1991–2020 average to the 2035–2049 average. Open-Meteo's free service is for
            non-commercial use.
        </>
    );
}
