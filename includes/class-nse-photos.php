<?php
/**
 * Customer photos on quote requests: validate, re-encode, store privately,
 * serve through signed links, and show on the lead screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NSE_Photos {

	const META     = '_pe_photos';
	const DIR      = 'pe-photos';
	const MAX      = 6;
	const MAX_EDGE = 2000;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'delete_for_lead' ) );
		add_action( 'add_meta_boxes_nse_lead', array( __CLASS__, 'meta_box' ) );
	}

	/* ---------- Storage ---------- */

	public static function dir() {
		$up  = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . self::DIR;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $dir;
	}

	/**
	 * Uploaded files from a request as a flat list of [tmp_name, size, error].
	 */
	public static function from_request( WP_REST_Request $req ) {
		$files = $req->get_file_params();
		if ( empty( $files['photos'] ) || ! is_array( $files['photos'] ) ) {
			return array();
		}
		$f   = $files['photos'];
		$out = array();
		if ( is_array( $f['tmp_name'] ) ) {
			foreach ( $f['tmp_name'] as $i => $tmp ) {
				$out[] = array( 'tmp' => $tmp, 'size' => (int) $f['size'][ $i ], 'error' => (int) $f['error'][ $i ] );
			}
		} else {
			$out[] = array( 'tmp' => $f['tmp_name'], 'size' => (int) $f['size'], 'error' => (int) $f['error'] );
		}
		return array_slice( $out, 0, self::MAX );
	}

	/**
	 * Keep only real photos. Returns [valid uploads, number skipped].
	 */
	public static function validate( array $uploads ) {
		$ok      = array();
		$skipped = 0;
		foreach ( $uploads as $u ) {
			if ( $u['error'] || ! $u['tmp'] || ! is_uploaded_file( $u['tmp'] ) || $u['size'] > 12 * MB_IN_BYTES ) {
				$skipped++;
				continue;
			}
			$info = @getimagesize( $u['tmp'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! $info || ! in_array( $info[2], array( IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP ), true ) || $info[0] * $info[1] > 50000000 ) {
				$skipped++;
				continue;
			}
			$u['info'] = $info;
			$ok[]      = $u;
		}
		return array( $ok, $skipped );
	}

	/**
	 * Re-encode as JPEG (drops any hidden data, including GPS location) and store. Returns saved records.
	 */
	public static function store( $lead_id, array $valid ) {
		$dir   = self::dir();
		$saved = array();
		foreach ( $valid as $i => $u ) {
			$name = (int) $lead_id . '-' . ( $i + 1 ) . '-' . wp_generate_password( 20, false, false ) . '.jpg';
			$path = $dir . '/' . $name;
			$w    = $u['info'][0];
			$h    = $u['info'][1];
			if ( function_exists( 'imagecreatefromstring' ) ) {
				$src = @imagecreatefromstring( file_get_contents( $u['tmp'] ) ); // phpcs:ignore
				if ( ! $src ) {
					continue;
				}
				$scale = min( 1, self::MAX_EDGE / max( $w, $h ) );
				$nw    = max( 1, (int) round( $w * $scale ) );
				$nh    = max( 1, (int) round( $h * $scale ) );
				$dst   = imagecreatetruecolor( $nw, $nh );
				imagefill( $dst, 0, 0, imagecolorallocate( $dst, 255, 255, 255 ) );
				imagecopyresampled( $dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h );
				imagejpeg( $dst, $path, 85 );
				imagedestroy( $src );
				imagedestroy( $dst );
				$w = $nw;
				$h = $nh;
			} elseif ( IMAGETYPE_JPEG === $u['info'][2] ) {
				copy( $u['tmp'], $path );
			} else {
				continue;
			}
			$saved[] = array( 'file' => $name, 'w' => $w, 'h' => $h );
		}
		if ( $saved ) {
			update_post_meta( $lead_id, self::META, $saved );
		}
		return $saved;
	}

	public static function records( $lead_id ) {
		$r = get_post_meta( $lead_id, self::META, true );
		return is_array( $r ) ? $r : array();
	}

	/**
	 * Local file paths that still exist.
	 */
	public static function paths( $lead_id ) {
		$out = array();
		foreach ( self::records( $lead_id ) as $r ) {
			$p = self::dir() . '/' . basename( $r['file'] );
			if ( file_exists( $p ) ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	public static function key( $lead_id, $n, $file ) {
		return substr( hash_hmac( 'sha256', 'pe-photo|' . (int) $lead_id . '|' . (int) $n . '|' . $file, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * Signed links to each photo (for the admin, emails, the CRM webhook, and CSV).
	 */
	public static function urls( $lead_id ) {
		$out = array();
		foreach ( self::records( $lead_id ) as $n => $r ) {
			$out[] = add_query_arg( 'key', self::key( $lead_id, $n, $r['file'] ), rest_url( 'nse/v1/photo/' . (int) $lead_id . '/' . (int) $n ) );
		}
		return $out;
	}

	public static function delete_for_lead( $post_id ) {
		if ( 'nse_lead' !== get_post_type( $post_id ) ) {
			return;
		}
		foreach ( self::paths( $post_id ) as $p ) {
			wp_delete_file( $p );
		}
	}

	/* ---------- Serving ---------- */

	public static function routes() {
		register_rest_route(
			'nse/v1',
			'/photo/(?P<id>\d+)/(?P<n>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'serve' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function serve( WP_REST_Request $req ) {
		$id   = absint( $req['id'] );
		$n    = absint( $req['n'] );
		$recs = 'nse_lead' === get_post_type( $id ) ? self::records( $id ) : array();
		if ( ! isset( $recs[ $n ] ) || ! hash_equals( self::key( $id, $n, $recs[ $n ]['file'] ), (string) $req->get_param( 'key' ) ) ) {
			return new WP_Error( 'pe_photo_denied', 'This photo link is not valid.', array( 'status' => 403 ) );
		}
		$path = self::dir() . '/' . basename( $recs[ $n ]['file'] );
		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'pe_photo_missing', 'This photo is no longer available.', array( 'status' => 404 ) );
		}
		nocache_headers();
		header( 'Content-Type: image/jpeg' );
		header( 'Content-Disposition: inline; filename="photo-' . ( $n + 1 ) . '.jpg"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/* ---------- Lead screen ---------- */

	public static function meta_box() {
		global $post;
		if ( $post && self::records( $post->ID ) ) {
			add_meta_box( 'pe_photos', 'Customer photos', array( __CLASS__, 'render_box' ), 'nse_lead', 'normal', 'high' );
		}
	}

	public static function render_box( $post ) {
		echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px">';
		foreach ( self::urls( $post->ID ) as $i => $u ) {
			printf(
				'<a href="%1$s" target="_blank" rel="noopener" style="display:block;aspect-ratio:4/3;overflow:hidden;border-radius:4px;background:#f0f0f1"><img src="%1$s" alt="Customer photo %2$d" loading="lazy" style="width:100%%;height:100%%;object-fit:cover;display:block"></a>',
				esc_url( $u ),
				(int) $i + 1
			);
		}
		echo '</div><p class="description">Click a photo to open it full size. Photos are private and only open through these links.</p>';
	}
}
