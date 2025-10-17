<?php
/**
 * Enforcement decision representation.
 *
 * @package CSH_AI_License
 */

namespace CSH\AI_License\Blocking;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable decision describing enforcement outcome.
 */
class Decision {

	public const ACTION_ALLOW  = 'allow';
	public const ACTION_BLOCK  = 'block';
	public const ACTION_CHALLENGE = 'challenge';

	/**
	 * @var string
	 */
	private $action;

	/**
	 * @var int
	 */
	private $status_code;

	/**
	 * @var string
	 */
	private $reason;

	/**
	 * @var array<string, string>
	 */
	private $headers;

	/**
	 * @var string|null
	 */
	private $body;

	/**
	 * @var array
	 */
	private $context;

	/**
	 * Constructor.
	 *
	 * @param string               $action Action identifier.
	 * @param int                  $status_code HTTP status.
	 * @param string               $reason Reason message.
	 * @param array<string,string> $headers Response headers.
	 * @param string|null          $body Response body.
	 * @param array                $context Additional context.
	 */
	public function __construct(
		string $action,
		int $status_code,
		string $reason,
		array $headers = [],
		?string $body = null,
		array $context = []
	) {
		$this->action      = $action;
		$this->status_code = $status_code;
		$this->reason      = $reason;
		$this->headers     = $headers;
		$this->body        = $body;
		$this->context     = $context;
	}

	public function action(): string {
		return $this->action;
	}

	public function status_code(): int {
		return $this->status_code;
	}

	public function reason(): string {
		return $this->reason;
	}

	/**
	 * Response headers.
	 *
	 * @return array<string,string>
	 */
	public function headers(): array {
		return $this->headers;
	}

	/**
	 * Response body (if any).
	 *
	 * @return string|null
	 */
	public function body(): ?string {
		return $this->body;
	}

	/**
	 * Additional context (score, triggers, etc).
	 *
	 * @return array
	 */
	public function context(): array {
		return $this->context;
	}
}
