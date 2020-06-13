<?php
/**
 * @copyright 2014-2020 Roman Parpalak
 * @license   http://www.opensource.org/licenses/mit-license.php MIT
 * @package   Upmath Latex Renderer
 * @link      https://i.upmath.me
 */

namespace S2\Tex\Renderer;

use Psr\Log\LoggerInterface;
use S2\Tex\TemplaterInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs Latex CLI.
 */
class Renderer implements RendererInterface
{
	private const SVG_PRECISION = 5;

	/**
	 * @var TemplaterInterface
	 */
	private $templater;

	/**
	 * @var PngConverter
	 */
	protected $pngConverter;

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @var string
	 */
	protected $tmpDir;

	/**
	 * @var bool
	 */
	private $isDebug = false;

	private $latexCommand;
	private $pngCommand;
	private $svgCommand;

	public function __construct(
		TemplaterInterface $templater,
		string $tmpDir,
		string $latexCommand,
		string $svgCommand,
		?string $pngCommand = null
	) {
		$this->templater = $templater;

		$this->tmpDir = $tmpDir;

		$this->latexCommand = $latexCommand;
		$this->svgCommand   = $svgCommand;
		$this->pngCommand   = $pngCommand;
	}

	public function setIsDebug(bool $isDebug): self
	{
		$this->isDebug = $isDebug;

		return $this;
	}

	public function setLogger(LoggerInterface $logger): self
	{
		$this->logger = $logger;

		return $this;
	}

	private function validateFormula(string $formula): void
	{
		foreach (['\\write', '\\input', '\\usepackage', '\\special'] as $disabledCommand) {
			if (strpos($formula, $disabledCommand) !== false) {
				if ($this->logger !== null) {
					$this->logger->error(sprintf('Forbidden command "%s": ', $disabledCommand), [$formula]);
					$this->logger->error('Server vars: ', $_SERVER);
				}
				throw new \RuntimeException('Forbidden commands.');
			}
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function run(string $formula, string $type): string
	{
		$this->validateFormula($formula);

		$tmpName = tempnam($this->tmpDir, '');

		$formulaObj = $this->templater->run($formula);
		$texSource  = $formulaObj->getText();
		$this->echoDebug(htmlspecialchars($texSource));

		// Latex
		file_put_contents($tmpName, $texSource);

		// See https://github.com/symfony/symfony/issues/5030 for 'exec' hack
		$process = new Process('exec ' . $this->latexCommand . ' ' . $tmpName . ' 2>&1');
		$process->setTimeout(8);

		try {
			$exitCode = $process->run();
		} catch (\Exception $e) {
			if ($this->logger !== null) {
				$message = $e instanceof ProcessTimedOutException ? 'Latex has been interrupted by a timeout' : 'Cannot run Latex';
				$this->logger->error($message, [
					'message' => $e->getMessage(),
					'command' => $process->getCommandLine(),
					'source'  => $texSource,
				]);
			}
			$this->dumpDebug($texSource);
			$this->cleanupTempFiles($tmpName);
			throw $e;
		}

		if ($this->isDebug) {
			echo '<pre>';
			readfile($tmpName . '.log');
			/** @noinspection ForgottenDebugOutputInspection */
			var_dump('exitcode', $exitCode);
			echo '</pre>';
		}

		if (!file_exists($tmpName . '.dvi')) {
			// Ohe has to figure out why the process was killed and why no dvi-file is created.
			if ($this->logger !== null) {
				$this->logger->error('Latex finished incorrectly', [
					'command'                   => $process->getCommandLine(),
					'exit_code'                 => $process->getExitCode(),
					'exit_code_text'            => $process->getExitCodeText(),
					"file_exists($tmpName.dvi)" => file_exists($tmpName . '.dvi'),
				]);
				$this->logger->error('source', [$texSource]);
				$this->logger->error('trace (' . $tmpName . '.log)', [file_get_contents($tmpName . '.log')]);
			}

			$this->dumpDebug($this);
			$this->cleanupTempFiles($tmpName);
			throw new \RuntimeException('Invalid formula');
		}

		// DVI -> SVG
		$cmd       = sprintf($this->svgCommand, $tmpName);
		$svgOutput = shell_exec($cmd);

		$this->dumpDebug($cmd);
		$this->dumpDebug($svgOutput);

		$svgContent = $this->processSvgContent(file_get_contents($tmpName . '.svg'), $formulaObj->hasBaseline());

		if ($type === 'png') {
			if ($this->pngConverter) {
				// SVG -> PNG
				$pngContent = $this->pngConverter->convert($tmpName . '.svg');
			}
			if ($this->pngCommand) {
				// DVI -> PNG
				exec(sprintf($this->pngCommand, $tmpName));
				$pngContent = file_get_contents($tmpName . '.png');
			}
		}

		// Cleaning up
		$this->cleanupTempFiles($tmpName);

		return $type === 'png' ? $pngContent : $svgContent;
	}

	private function cleanupTempFiles($tmpName): void
	{
		foreach (['', '.log', '.aux', '.dvi', '.svg', '.png'] as $ext) {
			@unlink($tmpName . $ext);
		}
	}

	public function setPngConverter(PngConverter $pngConverter): self
	{
		$this->pngConverter = $pngConverter;

		return $this;
	}

	/**
	 * @param mixed $output
	 */
	private function dumpDebug($output): void
	{
		if ($this->isDebug) {
			echo '<pre>';
			/** @noinspection ForgottenDebugOutputInspection */
			var_dump($output);
			echo '</pre>';
		}
	}

	private function echoDebug(string $output): void
	{
		if ($this->isDebug) {
			echo '<pre>';
			echo $output;
			echo '</pre>';
		}
	}

	private function processSvgContent(string $svg, bool $hasBaseline): string
	{
		// $svg = '...<!--start 19.8752 31.3399 -->...';

		//                                    x        y
		$hasStart = preg_match('#<!--start (-?[\d\.]+) (-?[\d\.]+) -->#', $svg, $matchStart);
		//                                  x        y        w        h
		$hasBbox = preg_match('#<!--bbox (-?[\d\.]+) (-?[\d\.]+) (-?[\d\.]+) (-?[\d\.]+) -->#', $svg, $matchBbox);

		if ($hasStart && $hasBbox) {
			// SVG contains info about image size and baseline position.
			[, , $rawY, $rawWidth, $rawHeight] = $matchBbox;

			$rawStartY = $matchStart[2];

			// Typically $rawY < $rawStartY
			$rawDepth = $hasBaseline ? min(0, $rawY - $rawStartY) + $rawHeight : $rawHeight * 0.5;

			// Taking into account OUTER_SCALE since coordinates are in the internal scale.
			$depth  = round(OUTER_SCALE * $rawDepth, self::SVG_PRECISION);
			$height = round(OUTER_SCALE * $rawHeight, self::SVG_PRECISION);
			$width  = round(OUTER_SCALE * $rawWidth, self::SVG_PRECISION);

			// Embedding script providing that info to the parent.
			$script = '<script type="text/ecmascript">if(window.parent.postMessage)window.parent.postMessage("' . $depth . '|' . $width . '|' . $height . '|"+window.location,"*");</script>' . "\n";
			$svg    = str_replace('</svg>', $script . '</svg>', $svg);
		}

		return $svg;
	}
}
