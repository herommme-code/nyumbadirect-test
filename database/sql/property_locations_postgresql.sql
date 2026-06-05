CREATE TABLE IF NOT EXISTS property_locations (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL,
    user_email VARCHAR(255) NOT NULL,
    listing_id VARCHAR(255) NOT NULL,
    latitude NUMERIC(10, 8) NOT NULL CHECK (latitude BETWEEN -90 AND 90),
    longitude NUMERIC(11, 8) NOT NULL CHECK (longitude BETWEEN -180 AND 180),
    source VARCHAR(255) NOT NULL DEFAULT 'gps',
    registered_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ,
    updated_at TIMESTAMPTZ,
    CONSTRAINT property_locations_user_listing_unique UNIQUE (user_id, listing_id)
);

CREATE INDEX IF NOT EXISTS property_locations_user_id_index
    ON property_locations (user_id);

CREATE INDEX IF NOT EXISTS property_locations_user_email_index
    ON property_locations (user_email);

CREATE INDEX IF NOT EXISTS property_locations_coordinates_index
    ON property_locations (latitude, longitude);
