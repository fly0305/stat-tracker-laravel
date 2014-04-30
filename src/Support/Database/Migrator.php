<?php

/**
 * Part of the Tracker package.
 *
 * NOTICE OF LICENSE
 *
 * Licensed under the 3-clause BSD License.
 *
 * This source file is subject to the 3-clause BSD License that is
 * bundled with this package in the LICENSE file.  It is also available at
 * the following URL: http://www.opensource.org/licenses/BSD-3-Clause
 *
 * @package    Tracker
 * @version    1.0.0
 * @author     Antonio Carlos Ribeiro @ PragmaRX
 * @license    BSD License (3-clause)
 * @copyright  (c) 2013, PragmaRX
 * @link       http://pragmarx.com
 */

namespace PragmaRX\Tracker\Support\Database;

use Illuminate\Database\Schema\Builder as Schema;

class Migrator
{

    public function __construct(Schema $schema)
    {
        $this->schema = $schema;
    }

    public function up()
    {
        $this->schema->create('tracker_accesses', function($table) {
            $table->increments('id');
            
            $table->string('session_id');
            $table->string('path_info');

            $table->timestamps();
        });

        $this->schema->create('tracker_agents', function($table) {
            $table->increments('id');
            
            $table->string('name')->unique();
            $table->string('browser');
            $table->string('browser_version');

            $table->timestamps();
        });

        $this->schema->create('tracker_cookies', function($table) {
            $table->increments('id');
            
            $table->string('uuid')->unique();

            $table->timestamps();
        });

        $this->schema->create('tracker_devices', function($table) {
            $table->increments('id');
            
            $table->string('kind');
            $table->string('model');
            $table->string('platform');
            $table->string('platform_version');
            $table->boolean('is_mobile');
            $table->string('agent_id');

            $table->unique(['kind', 'model', 'platform', 'platform_version']);

            $table->timestamps();
        });

        $this->schema->create('tracker_sessions', function($table) {
            $table->increments('id');
            
            $table->string('uuid')->unique();
            $table->string('user_id')->nullable();
            $table->string('device_id');
            $table->string('client_ip');
            $table->string('cookie_id')->nullable();
            $table->timestamp('last_activity');

            $table->timestamps();
        });
    }

    public function down()
    {
        $this->schema->drop('tracker_sessions');

        $this->schema->drop('tracker_devices');

        $this->schema->drop('tracker_cookies');

        $this->schema->drop('tracker_agents');

        $this->schema->drop('tracker_accesses');
    }

}