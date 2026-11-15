ALTER TABLE claim_queues MODIFY queue_code VARCHAR(32) NOT NULL;
ALTER TABLE claim_queues DROP INDEX uq_claim_queue_slot;
ALTER TABLE claim_queues ADD UNIQUE KEY uq_claim_queue_number (queue_number);
