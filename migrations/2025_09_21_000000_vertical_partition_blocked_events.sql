-- Vertical partitioning of blocked_events table
-- Move message column to blocked_event_messages table

-- Create the new table for messages
CREATE TABLE blocked_event_messages (
    id BIGINT UNSIGNED PRIMARY KEY,
    message TEXT,
    FOREIGN KEY (id) REFERENCES blocked_events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Copy existing message data
INSERT INTO blocked_event_messages (id, message)
SELECT id, message FROM blocked_events WHERE message IS NOT NULL AND message != '';

-- Drop the message column from blocked_events
ALTER TABLE blocked_events DROP COLUMN message;