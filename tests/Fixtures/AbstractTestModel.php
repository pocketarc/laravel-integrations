<?php

declare(strict_types=1);

namespace Integrations\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** An abstract base of the kind a consumer hangs their own models off. */
abstract class AbstractTestModel extends Model {}
