<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Minimal stand-ins for the Doctrine DBAL constants the OCP query-builder
 * interfaces refer to, loaded only when the app is tested outside a server
 * tree (no 3rdparty/). They let unit tests mock IQueryBuilder; no test
 * relies on their values.
 */

namespace Doctrine\DBAL {
	final class ParameterType {
		public const NULL = 0;
		public const INTEGER = 1;
		public const STRING = 2;
		public const LARGE_OBJECT = 3;
		public const BOOLEAN = 5;
		public const BINARY = 16;
		public const ASCII = 17;
	}

	final class ArrayParameterType {
		public const INTEGER = 101;
		public const STRING = 102;
		public const ASCII = 117;
		public const BINARY = 116;
	}
}

namespace Doctrine\DBAL\Types {
	final class Types {
		public const BIGINT = 'bigint';
		public const BLOB = 'blob';
		public const BOOLEAN = 'boolean';
		public const DATE_IMMUTABLE = 'date_immutable';
		public const DATE_MUTABLE = 'date';
		public const DATETIME_IMMUTABLE = 'datetime_immutable';
		public const DATETIME_MUTABLE = 'datetime';
		public const DATETIMETZ_IMMUTABLE = 'datetimetz_immutable';
		public const DATETIMETZ_MUTABLE = 'datetimetz';
		public const FLOAT = 'float';
		public const INTEGER = 'integer';
		public const JSON = 'json';
		public const SMALLINT = 'smallint';
		public const STRING = 'string';
		public const TEXT = 'text';
		public const TIME_IMMUTABLE = 'time_immutable';
		public const TIME_MUTABLE = 'time';
	}
}

namespace Doctrine\DBAL\Query\Expression {
	class ExpressionBuilder {
		public const EQ = '=';
		public const NEQ = '<>';
		public const LT = '<';
		public const LTE = '<=';
		public const GT = '>';
		public const GTE = '>=';
	}
}
