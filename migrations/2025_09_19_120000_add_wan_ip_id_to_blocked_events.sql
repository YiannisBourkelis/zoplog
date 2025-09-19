-- Add wan_ip_id column to blocked_events table to store the ID of the IP address from the internet side
ALTER TABLE blocked_events ADD COLUMN wan_ip_id INT NULL AFTER dst_ip_id;
ALTER TABLE blocked_events ADD CONSTRAINT fk_blocked_events_wan_ip_id FOREIGN KEY (wan_ip_id) REFERENCES ip_addresses(id);

-- Populate wan_ip_id for existing data based on traffic direction
UPDATE blocked_events SET wan_ip_id = CASE
    WHEN direction = 'IN' THEN src_ip_id
    WHEN direction IN ('OUT', 'FWD') THEN dst_ip_id
    ELSE src_ip_id
END WHERE wan_ip_id IS NULL;