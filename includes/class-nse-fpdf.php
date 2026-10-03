<?php
/**
 * FPDF subclass with the estimate footer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NSE_Fpdf extends FPDF {

	public $footer_txt = '';
	public $muted      = array( 93, 111, 114 );

	/**
	 * Inner padding FPDF adds before text in every cell (default 1 mm).
	 * Set to 0 so text lines up exactly with margins, rules, and images.
	 */
	public function set_cell_padding( $mm ) {
		$this->cMargin = $mm;
	}

	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- FPDF hook name.
	public function Footer() {
		$this->SetY( -13 );
		$this->SetDrawColor( 213, 220, 219 );
		$this->SetLineWidth( 0.2 );
		$this->Line( 16, $this->GetY(), $this->GetPageWidth() - 16, $this->GetY() );
		$this->Ln( 2 );
		$this->SetFont( 'Helvetica', '', 8 );
		$this->SetTextColor( $this->muted[0], $this->muted[1], $this->muted[2] );
		$this->Cell( 0, 5, $this->footer_txt, 0, 0, 'L' );
		$this->SetX( 16 );
		$this->Cell( 0, 5, 'Page ' . $this->PageNo(), 0, 0, 'R' );
	}
}
