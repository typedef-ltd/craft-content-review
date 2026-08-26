<?php

namespace typedef\contentreview\migrations;

use craft\db\Migration;
use typedef\contentreview\records\ReviewRecord;
use typedef\contentreview\records\ReviewScheduleRecord;

/**
 * Plugin install migration for Content Review.
 *
 * Creates the schedules table, indexes, and foreign keys.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        // Create schedules table
        if ($this->db->schema->getTableSchema(ReviewScheduleRecord::tableName(), true) === null) {
            $this->createTable(ReviewScheduleRecord::tableName(), [
                'id' => $this->primaryKey(),
                'elementId' => $this->integer()->notNull(),
                'elementType' => $this->string()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'explicitReviewOn' => $this->date()->null(),
                'nextReviewOn' => $this->date()->null(),
                'lastReviewedOn' => $this->date()->null(),
                'reviewerUserId' => $this->integer()->null(),
                'enabled' => $this->boolean()->notNull()->defaultValue(true),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            /*
             * Indexes
             */

            $this->createIndex(null, ReviewScheduleRecord::tableName(), ['enabled']);

            // Ensure siteId exists and is indexed
            $this->createIndex(
                null,
                ReviewScheduleRecord::tableName(),
                ['siteId']
            );

            // Unique per (element, site)
            $this->createIndex(
                'idx_' . ReviewScheduleRecord::TABLE_NAME . '_element_site_unique',
                ReviewScheduleRecord::tableName(),
                ['elementId', 'siteId'],
                true
            );

            // Site-scoped due lookups (common for Utilities filter-by-site)
            $this->createIndex(
                'idx_' . ReviewScheduleRecord::TABLE_NAME . '_site_nextreviewon',
                ReviewScheduleRecord::tableName(),
                ['siteId', 'nextReviewOn'],
            );

            // Global due lookups (e.g., digest across sites)
            $this->createIndex(
                'idx_' . ReviewScheduleRecord::TABLE_NAME . '_nextreviewon',
                ReviewScheduleRecord::tableName(),
                ['nextReviewOn'],
            );

            // Recipient-scoped due lookups (build digests per reviewer efficiently)
            $this->createIndex(
                'idx_' . ReviewScheduleRecord::TABLE_NAME . '_reviewer_nextreviewon',
                ReviewScheduleRecord::tableName(),
                ['reviewerUserId', 'nextReviewOn'],
            );

            /*
             * Foreign keys
             */

            $this->addForeignKey(
                'fk_' . ReviewScheduleRecord::TABLE_NAME . '_element',
                ReviewScheduleRecord::tableName(),
                ['elementId'],
                '{{%elements}}',
                ['id'],
                'CASCADE',
                'CASCADE'
            );

            $this->addForeignKey(
                'fk_' . ReviewScheduleRecord::TABLE_NAME . '_site',
                ReviewScheduleRecord::tableName(),
                ['siteId'],
                '{{%sites}}',
                ['id'],
                'CASCADE',
                'CASCADE'
            );

            $this->addForeignKey(
                'fk_' . ReviewScheduleRecord::TABLE_NAME . '_reviewer',
                ReviewScheduleRecord::tableName(),
                ['reviewerUserId'],
                '{{%users}}',
                ['id'],
                'SET NULL',
                'CASCADE'
            );
        }

        // Create reviews table
        if ($this->db->schema->getTableSchema(ReviewRecord::tableName(), true) === null) {
            $this->createTable(ReviewRecord::tableName(), [
                'id' => $this->primaryKey(),
                'elementId' => $this->integer()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'dueOn' => $this->date()->notNull(),
                'reviewedOn' => $this->date()->notNull(),
                'reviewedByUserId' => $this->integer()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
            ]);

            /* Indexes */

            // Ensure siteId exists and is indexed
            $this->createIndex(
                null,
                ReviewRecord::tableName(),
                ['siteId']
            );

            /* Foreign keys */

            $this->addForeignKey(
                'fk_' . ReviewRecord::TABLE_NAME . '_element',
                ReviewRecord::tableName(),
                ['elementId'],
                '{{%elements}}',
                ['id'],
                'CASCADE',
                'CASCADE'
            );

            $this->addForeignKey(
                'fk_' . ReviewRecord::TABLE_NAME . '_site',
                ReviewRecord::tableName(),
                ['siteId'],
                '{{%sites}}',
                ['id'],
                'CASCADE',
                'CASCADE'
            );

            $this->addForeignKey(
                'fk_' . ReviewRecord::TABLE_NAME . '_reviewer',
                ReviewRecord::tableName(),
                ['reviewedByUserId'],
                '{{%users}}',
                ['id'],
                'SET NULL',
                'CASCADE'
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->schema->getTableSchema(ReviewScheduleRecord::tableName(), true) !== null) {
            // Drop FKs explicitly (clear and deterministic)
            $this->dropForeignKey('fk_' . ReviewScheduleRecord::TABLE_NAME . '_reviewer', ReviewScheduleRecord::tableName());
            $this->dropForeignKey('fk_' . ReviewScheduleRecord::TABLE_NAME . '_site', ReviewScheduleRecord::tableName());
            $this->dropForeignKey('fk_' . ReviewScheduleRecord::TABLE_NAME . '_element', ReviewScheduleRecord::tableName());

            // Drop indexes explicitly
            $this->dropIndex('idx_' . ReviewScheduleRecord::TABLE_NAME . '_reviewer_nextreviewon', ReviewScheduleRecord::tableName());
            $this->dropIndex('idx_' . ReviewScheduleRecord::TABLE_NAME . '_nextreviewon', ReviewScheduleRecord::tableName());
            $this->dropIndex('idx_' . ReviewScheduleRecord::TABLE_NAME . '_site_nextreviewon', ReviewScheduleRecord::tableName());
            $this->dropIndex('idx_' . ReviewScheduleRecord::TABLE_NAME . '_element_site_unique', ReviewScheduleRecord::tableName());

            // Finally, drop the table
            $this->dropTable(ReviewScheduleRecord::tableName());
        }

        if ($this->db->schema->getTableSchema(ReviewRecord::tableName(), true) !== null) {
            // Drop FKs explicitly (clear and deterministic)
            $this->dropForeignKey('fk_' . ReviewRecord::TABLE_NAME . '_reviewer', ReviewRecord::tableName());
            $this->dropForeignKey('fk_' . ReviewRecord::TABLE_NAME . '_site', ReviewRecord::tableName());
            $this->dropForeignKey('fk_' . ReviewRecord::TABLE_NAME . '_element', ReviewRecord::tableName());

            $this->dropTable(ReviewRecord::tableName());
        }

        return true;
    }
}
