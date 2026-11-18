CREATE TABLE IF NOT EXISTS submissions (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    author text NOT NULL,
    category text NOT NULL,
    title text NOT NULL,
    body text NOT NULL,
    status text NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft', 'pending', 'approved'))
);
