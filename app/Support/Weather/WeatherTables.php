<?php

namespace App\Support\Weather;

/**
 * The tables the data endpoints may read and the columns each exposes: a copy of TABLE_SCHEMAS in the legacy
 * api/inc/tables.php (the file query.php and ingest.php shared), kept identical on purpose while both exist. Only the
 * `columns` are used here (this app never writes); `unique_key` is kept so the two files can be compared line for line.
 * Nothing a client sends is ever put into SQL as a table or column name unless it appears in these lists.
 */
final class WeatherTables
{
    public const SCHEMAS = [
        'cities' => [
            'unique_key' => ['name'],
            'columns' => ['name', 'place_type', 'latitude', 'longitude'],
        ],
        'weather_daily' => [
            'unique_key' => ['city_id', 'date'],
            'columns' => [
                'city_id', 'date', 'temp_max_f', 'temp_min_f', 'feels_max_f', 'feels_min_f',
                'precipitation_in', 'wind_gust_max_mph', 'weather_condition',
                'humidity_avg_pct', 'humidity_min_pct', 'humidity_max_pct',
                'pressure_avg_hpa', 'dew_point_avg_f', 'sunrise', 'sunset',
                'daylight_hours', 'sunshine_hours', 'solar_radiation_mj_m2', 'et0_mm',
                'wind_dir_dominant_deg', 'precip_hours', 'cloud_cover_avg_pct',
                'vpd_avg_kpa', 'soil_temp_0_7cm_f', 'soil_moisture_0_7cm_m3m3',
                'cloud_cover_low_avg_pct', 'cloud_cover_mid_avg_pct', 'cloud_cover_high_avg_pct',
                'direct_radiation_mj_m2', 'diffuse_radiation_mj_m2', 'direct_normal_irradiance_mj_m2',
                'wind_speed_avg_mph', 'snow_depth_in',
                'soil_temp_7_28cm_f', 'soil_temp_28_100cm_f', 'soil_temp_100_255cm_f',
                'soil_moisture_7_28cm_m3m3', 'soil_moisture_28_100cm_m3m3', 'soil_moisture_100_255cm_m3m3',
            ],
        ],
        'air_quality_daily' => [
            'unique_key' => ['city_id', 'date'],
            'columns' => [
                'city_id', 'date', 'aqi_us', 'pm2_5', 'pm10', 'ozone',
                'nitrogen_dioxide', 'sulphur_dioxide', 'carbon_monoxide', 'dust',
                'ammonia', 'uv_index_max',
            ],
        ],
        'live_conditions_log' => [
            'unique_key' => null, // append-only observation log, no natural upsert key
            'columns' => [
                'city_id', 'observed_at', 'temp_f', 'humidity_pct', 'pressure_hpa',
                'wind_speed_mph', 'wind_dir_deg', 'condition_text', 'active_alerts',
            ],
        ],
        'earthquakes' => [
            'unique_key' => ['usgs_event_id'],
            'columns' => [
                'usgs_event_id', 'event_time', 'magnitude', 'depth_km',
                'latitude', 'longitude', 'place',
                // Added 2026-10-12 (docs/schema/2026-10-12 earthquakes_type_status.sql): USGS's event
                // type (earthquake, quarry blast, ...) and review status (automatic, reviewed, deleted).
                'event_type', 'status',
            ],
        ],
        // D10 — weekly US Drought Monitor percent-area statistics, one county (San Bernardino, FIPS
        // 06071) for now. query.php can filter on ?area_fips= (see its KEY_FILTERS). Percentages are cumulative, as published; see docs/schema/.
        'drought_weekly' => [
            'unique_key' => ['area_fips', 'date'],
            'columns' => [
                'area_fips', 'date', 'none_pct', 'd0_pct', 'd1_pct', 'd2_pct', 'd3_pct', 'd4_pct',
                'valid_start', 'valid_end',
            ],
        ],
        // D9 — observed daily precipitation, snowfall and snow depth (inches) from NOAA GHCN-Daily,
        // one station today (USC00040741, Big Bear Lake). query.php can filter on ?station_id= (see its KEY_FILTERS). NULL means "not reported", not zero.
        'snow_observations' => [
            'unique_key' => ['station_id', 'date'],
            'columns' => [
                'station_id', 'date', 'precip_in', 'snowfall_in', 'snow_depth_in',
                'precip_attr', 'snowfall_attr', 'snow_depth_attr',
            ],
        ],
        // D7 — groundwater. `groundwater_readings` below is the 2026-07-23 placeholder, unused; these
        // two replace it (see docs/schema/2026-10-10 groundwater.sql). Depth is feet BELOW LAND
        // SURFACE, so a larger number is deeper water. Filter readings by well with ?site_id=.
        'groundwater_wells' => [
            'unique_key' => ['site_id'],
            'columns' => [
                'site_id', 'name', 'latitude', 'longitude', 'altitude_ft', 'well_depth_ft',
                'county', 'huc', 'aquifer_code', 'selected_on',
            ],
        ],
        'groundwater_levels' => [
            'unique_key' => ['site_id', 'measured_at'],
            'columns' => [
                'site_id', 'reading_date', 'measured_at', 'time_known', 'depth_ft',
                'qualifiers', 'approval', 'method',
            ],
        ],
        // D8 — climate projections, stored as monthly summaries in dated snapshots (see
        // docs/schema/2026-10-10 climate.sql). `climate_projections` below is the unused 2026-07-23
        // placeholder. The pages read through v1/climate_summary.php; these entries make the raw
        // monthly rows readable (?city_id=) and keep ingest.php's whitelist complete.
        'climate_snapshots' => [
            'unique_key' => ['snapshot'],
            'columns' => ['snapshot', 'pairs_expected'],
        ],
        'climate_monthly' => [
            'unique_key' => ['snapshot', 'city_id', 'model', 'year', 'month'],
            'columns' => [
                'snapshot', 'queried_on', 'city_id', 'model', 'year', 'month', 'days',
                'tmax_mean_f', 'tmin_mean_f', 'tmax_max_f', 'tmin_min_f', 'precip_in',
                'days_tmax_ge_100', 'days_tmax_ge_105', 'days_tmin_le_32', 'days_tmin_ge_70',
                'days_precip_ge_0_1', 'days_precip_ge_1',
            ],
        ],
        'groundwater_readings' => [
            'unique_key' => ['well_id', 'reading_date'],
            'columns' => ['well_id', 'reading_date', 'water_level_ft'],
        ],
        'stations' => [
            'unique_key' => ['network', 'external_id'],
            'columns' => ['external_id', 'name', 'network', 'latitude', 'longitude', 'elevation_ft'],
        ],
        'climate_projections' => [
            'unique_key' => null, // append-only by design — queried_on distinguishes revisions
            'columns' => [
                'city_id', 'model', 'scenario', 'projection_date', 'temp_max_f',
                'temp_min_f', 'precipitation_in', 'queried_on',
            ],
        ],
        'soil_profile' => [
            'unique_key' => ['city_id'],
            'columns' => [
                'city_id', 'map_unit_symbol', 'component_name', 'taxonomic_class',
                'drainage_class', 'hydrologic_soil_group', 'avg_water_capacity_in_per_in',
                'surface_sand_pct', 'surface_clay_pct', 'surface_ph',
                'depth_to_restrictive_layer_in', 'restriction_type', 'source',
            ],
        ],
        'cimis_daily' => [
            'unique_key' => ['city_id', 'date'],
            'columns' => ['city_id', 'date', 'eto_in', 'solar_rad_avg_ly'],
        ],
        'farmers_markets' => [
            'unique_key' => ['slug'],
            'columns' => [
                'slug', 'name', 'display_name', 'city', 'region',
                'market_type', 'certification_status', 'certificate_number',
                'operator_name', 'host_organization',
                'description_short', 'why_visit', 'best_for',
                'status', 'status_note', 'year_round',
                'venue_name', 'address', 'location_notes', 'parking_notes',
                'latitude', 'longitude',
                'schedule_summary', 'schedule_periods',
                'estimated_vendor_count_min', 'estimated_vendor_count_max',
                'product_categories', 'assistance_programs', 'payment_methods', 'features',
                'contact_phone', 'contact_email', 'official_url', 'social_accounts',
                'sources', 'verification_status', 'last_verified_at', 'data_notes',
            ],
            // best_for, schedule_periods, product_categories, assistance_programs,
            // payment_methods, features, social_accounts, sources, data_notes are
            // JSON columns — ingest.php's bindValue() can only bind scalars, so
            // callers must pass these as already-json_encode()'d strings, not
            // raw arrays. query.php returns them the same way: JSON-encoded
            // strings nested in the response, not pre-parsed objects.
        ],
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::SCHEMAS);
    }

    public static function has(mixed $table): bool
    {
        return is_string($table) && isset(self::SCHEMAS[$table]);
    }

    /** @return list<string> */
    public static function columns(string $table): array
    {
        return self::SCHEMAS[$table]['columns'];
    }
}
