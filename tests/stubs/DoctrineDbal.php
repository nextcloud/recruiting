<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * The constants of Doctrine DBAL that the OCP database interfaces refer to,
 * so the unit tests can mock IDBConnection and IQueryBuilder without the
 * server's 3rdparty directory. The values only need to be distinct.
 */

namespace Doctrine\DBAL {
	class ParameterType {
		public const NULL = 0;
		public const INTEGER = 1;
		public const STRING = 2;
		public const LARGE_OBJECT = 3;
		public const BOOLEAN = 5;
		public const BINARY = 16;
		public const ASCII = 17;
	}

	class ArrayParameterType {
		public const INTEGER = 101;
		public const STRING = 102;
		public const ASCII = 117;
		public const BINARY = 116;
	}
}

namespace Doctrine\DBAL\Types {
	class Types {
		public const BOOLEAN = 'boolean';
		public const DATE_MUTABLE = 'date';
		public const DATE_IMMUTABLE = 'date_immutable';
		public const DATETIME_MUTABLE = 'datetime';
		public const DATETIME_IMMUTABLE = 'datetime_immutable';
		public const DATETIMETZ_MUTABLE = 'datetimetz';
		public const DATETIMETZ_IMMUTABLE = 'datetimetz_immutable';
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
