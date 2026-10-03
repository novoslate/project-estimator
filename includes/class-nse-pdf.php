<?php
/**
 * Branded PDF estimates: build, store privately, and serve through signed links.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NSE_Pdf {

	const META = '_pe_pdf';
	const DIR  = 'pe-estimates';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'delete_for_lead' ) );
	}

	/* ---------- Storage ---------- */

	public static function dir() {
		$up  = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . self::DIR;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		/* Block direct browsing. Files also use random names, so they cannot be guessed. */
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $dir;
	}

	public static function path_for( $lead_id ) {
		$file = get_post_meta( $lead_id, self::META, true );
		if ( ! $file ) {
			return '';
		}
		$path = self::dir() . '/' . basename( $file );
		return file_exists( $path ) ? $path : '';
	}

	public static function key( $lead_id ) {
		return substr( hash_hmac( 'sha256', 'pe-pdf|' . (int) $lead_id . '|' . get_post_meta( $lead_id, self::META, true ), wp_salt( 'auth' ) ), 0, 32 );
	}

	public static function url( $lead_id ) {
		return add_query_arg( 'key', self::key( $lead_id ), rest_url( 'nse/v1/estimate/' . (int) $lead_id ) );
	}

	public static function delete_for_lead( $post_id ) {
		if ( 'nse_lead' !== get_post_type( $post_id ) ) {
			return;
		}
		$path = self::path_for( $post_id );
		if ( $path ) {
			wp_delete_file( $path );
		}
	}

	/* ---------- Download ---------- */

	public static function routes() {
		register_rest_route(
			'nse/v1',
			'/estimate/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'download' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function download( WP_REST_Request $req ) {
		$id  = absint( $req['id'] );
		$key = (string) $req->get_param( 'key' );
		if ( 'nse_lead' !== get_post_type( $id ) || ! hash_equals( self::key( $id ), $key ) ) {
			return new WP_Error( 'pe_pdf_denied', 'This estimate link is not valid.', array( 'status' => 403 ) );
		}
		$path = self::path_for( $id );
		if ( ! $path ) {
			return new WP_Error( 'pe_pdf_missing', 'This estimate is no longer available.', array( 'status' => 404 ) );
		}
		$lead = get_post_meta( $id, '_nse_lead', true );
		$name = sanitize_file_name( 'Estimate-' . ( isset( $lead['project'] ) ? $lead['project'] : 'project' ) . '-' . $id . '.pdf' );
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . $name . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Robots-Tag: noindex' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/* ---------- Images ---------- */

	/**
	 * Validate the browser's illustration (base64 JPEG) and write a clean copy to a temp file.
	 */
	public static function illustration_file( $b64 ) {
		if ( ! is_string( $b64 ) || '' === $b64 ) {
			return '';
		}
		$b64 = preg_replace( '#^data:image/jpeg;base64,#', '', $b64 );
		if ( strlen( $b64 ) > 3000000 ) {
			return '';
		}
		$bin = base64_decode( $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( ! $bin ) {
			return '';
		}
		$info = @getimagesizefromstring( $bin ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $info || IMAGETYPE_JPEG !== $info[2] || $info[0] > 2400 || $info[1] > 2400 ) {
			return '';
		}
		$tmp = wp_tempnam( 'pe-illustration' ) . '.jpg';
		if ( function_exists( 'imagecreatefromstring' ) ) {
			/* Re-encode so only clean pixel data reaches the PDF. */
			$im = @imagecreatefromstring( $bin ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! $im ) {
				return '';
			}
			imagejpeg( $im, $tmp, 88 );
			imagedestroy( $im );
		} else {
			file_put_contents( $tmp, $bin ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $tmp;
	}

	/**
	 * Logo file FPDF can read (JPEG or PNG), or ''.
	 */
	public static function logo_file( $attachment_id ) {
		if ( ! $attachment_id ) {
			return '';
		}
		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! file_exists( $path ) ) {
			return '';
		}
		$type = wp_check_filetype( $path );
		if ( in_array( $type['ext'], array( 'jpg', 'jpeg', 'png' ), true ) ) {
			/* Interlaced PNGs and 16-bit PNGs are not supported by FPDF; flatten them through GD when possible. */
			if ( 'png' === $type['ext'] && function_exists( 'imagecreatefrompng' ) ) {
				$im = @imagecreatefrompng( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( $im ) {
					imagealphablending( $im, false );
					imagesavealpha( $im, true );
					imageinterlace( $im, false );
					$tmp = wp_tempnam( 'pe-logo' ) . '.png';
					imagepng( $im, $tmp );
					imagedestroy( $im );
					return $tmp;
				}
			}
			return $path;
		}
		/* Other formats (webp, gif): convert to PNG through GD. */
		if ( function_exists( 'imagecreatefromstring' ) ) {
			$im = @imagecreatefromstring( file_get_contents( $path ) ); // phpcs:ignore
			if ( $im ) {
				imagesavealpha( $im, true );
				$tmp = wp_tempnam( 'pe-logo' ) . '.png';
				imagepng( $im, $tmp );
				imagedestroy( $im );
				return $tmp;
			}
		}
		return '';
	}

	/* ---------- Build ---------- */

	/**
	 * Create and store the PDF for a lead. Returns the stored path or ''.
	 */
	public static function create( $lead_id, array $lead, array $c, $illustration_b64 ) {
		$settings = NSE_Settings::get();
		if ( empty( $settings['pdf']['enabled'] ) ) {
			return '';
		}
		$img  = self::illustration_file( $illustration_b64 );
		$logo = self::logo_file( $settings['logo_id'] );
		$temp = array_filter( array( $img, false !== strpos( $logo, 'pe-logo' ) ? $logo : '' ) );

		try {
			$bytes = self::build( $lead, $c, NSE_Settings::resolve_design( $c ), $img, $logo );
		} catch ( Exception $e ) {
			$bytes = '';
		}
		foreach ( $temp as $t ) {
			wp_delete_file( $t );
		}
		if ( ! $bytes ) {
			return '';
		}

		$file = (int) $lead_id . '-' . wp_generate_password( 24, false, false ) . '.pdf';
		$path = self::dir() . '/' . $file;
		file_put_contents( $path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		update_post_meta( $lead_id, self::META, $file );
		return $path;
	}

	/**
	 * UTF-8 to the Windows-1252 encoding FPDF's built-in fonts use.
	 */
	public static function t( $s ) {
		$s = str_replace( array( "\u{2014}", "\u{2013}" ), '-', (string) $s );
		if ( function_exists( 'iconv' ) ) {
			$out = @iconv( 'UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $out ) {
				return $out;
			}
		}
		return function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $s, 'Windows-1252', 'UTF-8' ) : $s;
	}

	private static function rgb( $hex ) {
		$n = hexdec( ltrim( $hex, '#' ) );
		return array( ( $n >> 16 ) & 255, ( $n >> 8 ) & 255, $n & 255 );
	}

	public static function money( $n ) {
		return '$' . number_format( (float) $n );
	}

	/**
	 * Lay out the PDF and return its bytes.
	 */
	public static function build( array $lead, array $c, array $design, $img, $logo ) {
		if ( ! class_exists( 'FPDF' ) ) {
			require_once NSE_PATH . 'lib/fpdf/fpdf.php';
		}
		if ( ! class_exists( 'NSE_Fpdf' ) ) {
			require_once NSE_PATH . 'includes/class-nse-fpdf.php';
		}

		$biz    = $c['business'];
		$accent = self::rgb( $design['accent'] );
		$on     = self::rgb( NSE_Settings::on_color( $design['accent'] ) );
		$ink    = array( 29, 43, 46 );
		$muted  = array( 93, 111, 114 );
		$soft   = self::rgb( NSE_Settings::mix( '#FFFFFF', $design['accent'], 0.08 ) );
		$site   = wp_parse_url( home_url(), PHP_URL_HOST );

		$pdf             = new NSE_Fpdf( 'P', 'mm', 'Letter' );
		$pdf->footer_txt = self::t( implode( '   |   ', array_filter( array( $biz['name'], $biz['phone'], $site ) ) ) );
		$pdf->muted      = $muted;
		$pdf->SetTitle( 'Estimate ' . $lead['lead_id'], true );
		$pdf->SetAuthor( $biz['name'], true );
		$pdf->SetMargins( 16, 16, 16 );
		$pdf->SetAutoPageBreak( true, 18 );
		$pdf->AddPage();
		$W = $pdf->GetPageWidth() - 32;

		/* Header band */
		$pdf->SetFillColor( $accent[0], $accent[1], $accent[2] );
		$pdf->Rect( 0, 0, $pdf->GetPageWidth(), 30, 'F' );
		$right_x = 16;
		if ( $logo ) {
			try {
				$size = @getimagesize( $logo ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$h    = 16;
				$w    = $size ? min( 70, $h * $size[0] / max( 1, $size[1] ) ) : 40;
				$h    = $size ? $w * $size[1] / max( 1, $size[0] ) : $h;
				$pdf->Image( $logo, 16, 15 - $h / 2, $w, $h );
			} catch ( Exception $e ) {
				$logo = '';
			}
		}
		$pdf->SetTextColor( $on[0], $on[1], $on[2] );
		$contact_line = self::t( implode( '   ', array_filter( array( $biz['phone'], $site ) ) ) );
		if ( $logo ) {
			$pdf->SetXY( 16, 9.5 );
			$pdf->SetFont( 'Helvetica', 'B', 11 );
			$pdf->Cell( $W, 6, self::t( $biz['name'] ), 0, 2, 'R' );
			$pdf->SetFont( 'Helvetica', '', 10 );
			$pdf->Cell( $W, 5, $contact_line, 0, 2, 'R' );
		} else {
			/* No logo: business name on the left, contact details on the right, both centered in the band. */
			$pdf->SetXY( 16, 11 );
			$pdf->SetFont( 'Helvetica', 'B', 16 );
			$pdf->Cell( $W / 2, 8, self::t( $biz['name'] ) );
			$pdf->SetXY( 16, 12 );
			$pdf->SetFont( 'Helvetica', '', 10 );
			$pdf->Cell( $W, 6, $contact_line, 0, 2, 'R' );
		}

		/* Title */
		$pdf->SetXY( 16, 40 );
		$pdf->SetTextColor( $ink[0], $ink[1], $ink[2] );
		$pdf->SetFont( 'Helvetica', 'B', 20 );
		$pdf->Cell( $W, 9, self::t( 'Your ' . strtolower( $lead['project'] ) . ' estimate' ), 0, 1 );
		$pdf->SetFont( 'Helvetica', '', 10 );
		$pdf->SetTextColor( $muted[0], $muted[1], $muted[2] );
		$pdf->Cell( $W, 6, self::t( 'Prepared for ' . $lead['name'] . '   |   ' . date_i18n( 'F j, Y' ) . '   |   Estimate #' . $lead['lead_id'] ), 0, 1 );

		/* Price box */
		$y = $pdf->GetY() + 5;
		$pdf->SetFillColor( $soft[0], $soft[1], $soft[2] );
		$pdf->Rect( 16, $y, $W, 24, 'F' );
		$pdf->SetFillColor( $accent[0], $accent[1], $accent[2] );
		$pdf->Rect( 16, $y, 1.6, 24, 'F' );
		$pdf->SetXY( 22, $y + 4 );
		$pdf->SetFont( 'Helvetica', '', 10 );
		$pdf->SetTextColor( $muted[0], $muted[1], $muted[2] );
		$pdf->Cell( $W - 10, 5, self::t( 'Estimated price range' ), 0, 2 );
		$pdf->SetFont( 'Helvetica', 'B', 22 );
		$pdf->SetTextColor( $accent[0], $accent[1], $accent[2] );
		if ( array_sum( $accent ) > 600 ) {
			$pdf->SetTextColor( $ink[0], $ink[1], $ink[2] ); // Very light accents are hard to read on white.
		}
		$pdf->Cell( $W - 10, 11, self::t( self::money( $lead['estimate_low'] ) . ' to ' . self::money( $lead['estimate_high'] ) ), 0, 2 );
		$pdf->SetY( $y + 28 );

		/* Illustration */
		if ( $img ) {
			$size = @getimagesize( $img ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$iw   = $W;
			$ih   = $size ? $iw * $size[1] / max( 1, $size[0] ) : $iw * 0.65;
			if ( $ih > 86 ) {
				$ih = 86;
				$iw = $size ? $ih * $size[0] / max( 1, $size[1] ) : $W;
			}
			$x = 16 + ( $W - $iw ) / 2;
			$pdf->Image( $img, $x, $pdf->GetY(), $iw, $ih, 'JPG' );
			$pdf->SetY( $pdf->GetY() + $ih + 2 );
			$pdf->SetFont( 'Helvetica', 'I', 8 );
			$pdf->SetTextColor( $muted[0], $muted[1], $muted[2] );
			$pdf->Cell( $W, 4, self::t( 'Illustration for reference. Colors and details are finalized with you on site.' ), 0, 1, 'C' );
			$pdf->Ln( 3 );
		}

		/* Two columns: project details and customer info */
		$details = array(
			'Project' => $lead['project'],
			'Style'   => $lead['option'],
			'Color'   => isset( $lead['color'] ) ? $lead['color'] : '',
			'Size'    => ! empty( $lead['size'] ) ? $lead['size'] : $lead['measurements'] . ' (' . $lead['quantity'] . ')',
			'Extras'  => $lead['extras'] ? implode( ', ', $lead['extras'] ) : 'None',
		);
		$contact = array(
			'Name'     => $lead['name'],
			'Phone'    => $lead['phone'],
			'Email'    => $lead['email'],
			'ZIP'      => $lead['zip'],
			'Address'  => $lead['address'],
			'Timeline' => $lead['timeline'],
			'Notes'    => $lead['notes'],
		);
		$col = ( $W - 10 ) / 2;
		$top = $pdf->GetY();
		if ( $top > 210 ) {
			$pdf->AddPage();
			$top = $pdf->GetY();
		}
		$end1 = self::section( $pdf, 16, $top, $col, 'Project details', $details, $accent, $ink, $muted );
		$end2 = self::section( $pdf, 16 + $col + 10, $top, $col, 'Your information', $contact, $accent, $ink, $muted );
		$pdf->SetY( max( $end1, $end2 ) + 4 );

		/* Next steps and fine print */
		if ( ! empty( $c['success_message'] ) ) {
			self::heading( $pdf, $W, 'Next steps', $accent, $ink );
			$pdf->SetFont( 'Helvetica', '', 10 );
			$pdf->SetTextColor( $ink[0], $ink[1], $ink[2] );
			$pdf->MultiCell( $W, 5, self::t( $c['success_message'] ), 0, 'L' );
			$pdf->Ln( 3 );
		}
		if ( ! empty( $c['disclaimer'] ) ) {
			$pdf->SetFont( 'Helvetica', '', 8 );
			$pdf->SetTextColor( $muted[0], $muted[1], $muted[2] );
			$pdf->MultiCell( $W, 4, self::t( $c['disclaimer'] ), 0, 'L' );
		}

		return $pdf->Output( 'S' );
	}

	private static function heading( $pdf, $w, $label, $accent, $ink ) {
		$pdf->SetFont( 'Helvetica', 'B', 11 );
		$pdf->SetTextColor( $ink[0], $ink[1], $ink[2] );
		$pdf->Cell( $w, 6, self::t( $label ), 0, 1 );
		$pdf->SetDrawColor( $accent[0], $accent[1], $accent[2] );
		$pdf->SetLineWidth( 0.5 );
		$pdf->Line( $pdf->GetX(), $pdf->GetY(), $pdf->GetX() + 12, $pdf->GetY() );
		$pdf->Ln( 3 );
	}

	/**
	 * Label/value rows in a column. Returns the bottom Y.
	 */
	private static function section( $pdf, $x, $y, $w, $title, array $rows, $accent, $ink, $muted ) {
		$pdf->SetXY( $x, $y );
		$pdf->SetLeftMargin( $x );
		self::heading( $pdf, $w, $title, $accent, $ink );
		foreach ( $rows as $label => $val ) {
			if ( '' === trim( (string) $val ) ) {
				continue;
			}
			$pdf->SetX( $x );
			$pdf->SetFont( 'Helvetica', '', 9 );
			$pdf->SetTextColor( $muted[0], $muted[1], $muted[2] );
			$pdf->Cell( 22, 5.5, self::t( $label ) );
			$pdf->SetFont( 'Helvetica', '', 10 );
			$pdf->SetTextColor( $ink[0], $ink[1], $ink[2] );
			$pdf->MultiCell( $w - 22, 5.5, self::t( $val ), 0, 'L' );
		}
		$end = $pdf->GetY();
		$pdf->SetLeftMargin( 16 );
		return $end;
	}
}
