<?php

namespace Zanbug\Sdk\Support;

use Zanbug\Sdk\Zanbug;

/**
 * PDOStatement subclass used by TracedPdo (via PDO::ATTR_STATEMENT_CLASS):
 * times execute() and reports slow queries to Zanbug. Everything else is
 * inherited untouched. Reporting is fully guarded and NEVER throws.
 */
class TracedPdoStatement extends \PDOStatement
{
    /** @var string Driver name reported as the query "connection". */
    private $zanbugConnection;

    /** @var TracedPdo|null Owning PDO handle, used to run EXPLAIN on slow SELECTs. */
    private $zanbugPdo;

    /**
     * PDO instantiates statement classes itself; ctor args come from the
     * PDO::ATTR_STATEMENT_CLASS declaration in TracedPdo (driver name + the
     * owning PDO handle).
     */
    protected function __construct($connection = 'sql', $pdo = null)
    {
        $this->zanbugConnection = is_string($connection) && $connection !== '' ? $connection : 'sql';
        $this->zanbugPdo = ($pdo instanceof TracedPdo) ? $pdo : null;
    }

    #[\ReturnTypeWillChange]
    public function execute($params = null)
    {
        // Skip entirely while an EXPLAIN is running on the handle: this same
        // execute() fires for the EXPLAIN statement, and it must not be timed,
        // recorded or re-explained.
        if (TracedPdo::zanbugIsExplaining()) {
            return $params === null ? parent::execute() : parent::execute($params);
        }

        $start = microtime(true);
        $result = $params === null ? parent::execute() : parent::execute($params);

        try {
            $durationMs = (microtime(true) - $start) * 1000;
            $bindings = (is_array($params) && !empty($params)) ? array_values($params) : array();
            $meta = array('connection' => $this->zanbugConnection, 'explain_tried' => true);
            if (!empty($bindings)) {
                $meta['bindings'] = $bindings;
            }
            if ($this->zanbugPdo !== null) {
                $explain = $this->zanbugPdo->zanbugMaybeExplain((string) $this->queryString, $durationMs, $bindings);
                if (is_array($explain)) {
                    $meta['explain'] = $explain;
                }
            }
            Zanbug::recordQuery(
                (string) $this->queryString,
                $durationMs,
                $meta
            );
        } catch (\Exception $e) {
            // telemetry must be non-fatal
        } catch (\Throwable $e) {
            // non-fatal
        }

        return $result;
    }
}
