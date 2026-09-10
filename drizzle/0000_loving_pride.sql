CREATE TABLE `agents` (
	`id` text PRIMARY KEY NOT NULL,
	`name` text NOT NULL
);
--> statement-breakpoint
CREATE TABLE `generations` (
	`id` text PRIMARY KEY NOT NULL,
	`agent` text NOT NULL,
	`kind` text NOT NULL,
	`prompt` text NOT NULL,
	`result` text NOT NULL,
	`created` text NOT NULL
);
--> statement-breakpoint
CREATE TABLE `products` (
	`id` text PRIMARY KEY NOT NULL,
	`sku` text NOT NULL,
	`name` text NOT NULL,
	`description` text DEFAULT '' NOT NULL,
	`category` text DEFAULT '' NOT NULL,
	`image` text DEFAULT '' NOT NULL,
	`supplier_id` text,
	`supplier_aed` integer,
	`supplier_stock` integer,
	`supplier_sync` text,
	`warehouse_stock` integer DEFAULT 0 NOT NULL,
	`sale_baisa` integer,
	`cost_baisa` integer DEFAULT 0 NOT NULL
);
--> statement-breakpoint
CREATE UNIQUE INDEX `products_sku_unique` ON `products` (`sku`);--> statement-breakpoint
CREATE UNIQUE INDEX `products_supplier_id_unique` ON `products` (`supplier_id`);--> statement-breakpoint
CREATE TABLE `quotes` (
	`id` text PRIMARY KEY NOT NULL,
	`number` integer NOT NULL,
	`agent` text NOT NULL,
	`customer` text NOT NULL,
	`email` text DEFAULT '' NOT NULL,
	`notes` text DEFAULT '' NOT NULL,
	`status` text DEFAULT 'Draft' NOT NULL,
	`rate` real NOT NULL,
	`lines` text NOT NULL,
	`total` integer NOT NULL,
	`created` text NOT NULL,
	`updated` text NOT NULL,
	`revision` integer DEFAULT 1 NOT NULL
);
--> statement-breakpoint
CREATE UNIQUE INDEX `quotes_number_unique` ON `quotes` (`number`);--> statement-breakpoint
CREATE TABLE `settings` (
	`id` integer PRIMARY KEY NOT NULL,
	`rate` real NOT NULL,
	`company` text NOT NULL,
	`updated` text NOT NULL
);
