<?php 
namespace Podlove\Modules\Auphonic;
use \Podlove\Model;

class Auphonic extends \Podlove\Modules\Base {

    protected $module_name = 'Auphonic';
    protected $module_description = 'Import Episode data from an Auphonic production';
    protected $module_group = 'external services';
	
    public function load() {

			add_action( 'admin_print_styles', array( $this, 'admin_print_styles' ) );
    		
    		if($this->get_module_option('auphonic_api_key') == "") { } else {
    			add_action( 'podlove_episode_form_beginning', array( $this, 'auphonic_episodes' ), 10, 2 );
				// add_action( 'podlove_episode_form_beginning', array( $this, 'create_auphonic_production' ), 10, 2 );    			
    		}
    		
			if( isset( $_GET["page"] ) && $_GET["page"] == "podlove_settings_modules_handle") {
    			add_action('admin_bar_init', array( $this, 'check_code'));
    		}    		
    		
    		if( isset( $_GET["page"] ) && $_GET["page"] == "podlove_settings_modules_handle") {
    			add_action('admin_bar_init', array( $this, 'check_code'));
    		}  

    		if ( $this->get_module_option('auphonic_api_key') == "" ) {
    			$auth_url = "https://auphonic.com/oauth2/authorize/?client_id=0e7fac528c570c2f2b85c07ca854d9&redirect_uri=" . urlencode(get_site_url().'/wp-admin/admin.php?page=podlove_settings_modules_handle') . "&response_type=code";
	    		$description = '<i class="podlove-icon-remove"></i> '
	    		             . __( 'You need to allow Podlove Publisher to access your Auphonic account. You will be redirected to this page once the auth process completed.', 'podlove' )
	    		             . '<br><a href="' . $auth_url . '" class="button button-primary">' . __( 'Authorize now', 'podlove' ) . '</a>';
				$this->register_option( 'auphonic_api_key', 'hidden', array(
				'label'       => __( 'Authorization', 'podlove' ),
				'description' => $description,
				'html'        => array( 'class' => 'regular-text' )
				) );	
    		} else {
    			$ch = curl_init('https://auphonic.com/api/user.json');                                                                      
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
				curl_setopt($ch, CURLOPT_USERAGENT, \Podlove\Http\Curl::user_agent());                                                              
				curl_setopt($ch, CURLOPT_HTTPHEADER, array(                                     
					'Content-type: application/json',                                     
					'Authorization: Bearer '.$this->get_module_option('auphonic_api_key'))                                                                       
				);                                                              
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);        

				$decoded_user_information = json_decode(curl_exec($ch));
    		
    			if(isset($decoded_user_information) AND $decoded_user_information !== "") {
					$description = '<i class="podlove-icon-ok"></i> '
								 . sprintf(
									__( 'You are logged in as <strong>'.$decoded_user_information->data->username.'</strong>. If you want to logout, click %shere%s.', 'podlove' ),
									'<a href="' . admin_url( 'admin.php?page=podlove_settings_modules_handle&reset_auphonic_auth_code=1' ) . '">',
									'</a>'
								);
				} else {
					$description = '<i class="podlove-icon-remove"></i> '
								 . sprintf(
									__( 'Something went wrong with the Auphonic connection. Please reset the connection and authorize again. To do so click %shere%s', 'podlove' ),
									'<a href="' . admin_url( 'admin.php?page=podlove_settings_modules_handle&reset_auphonic_auth_code=1' ) . '">',
									'</a>'
								);			
				}
				$this->register_option( 'auphonic_api_key', 'hidden', array(
				'label'       => __( 'Authorization', 'podlove' ),
				'description' => $description,
				'html'        => array( 'class' => 'regular-text' )
				) );	
				
				// Fetch Auphonic presets
				
    			$ch = curl_init('https://auphonic.com/api/presets.json');                                                                      
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");       
				curl_setopt($ch, CURLOPT_USERAGENT, \Podlove\Http\Curl::user_agent());                                                              
				curl_setopt($ch, CURLOPT_HTTPHEADER, array(                                     
					'Content-type: application/json',                                     
					'Authorization: Bearer '.$this->get_module_option('auphonic_api_key'))                                                                       
				);                                                              
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);  	
				
				$decoded_presets = json_decode(curl_exec($ch));
				$preset_list = array();
				
				foreach($decoded_presets->data as $preset_id => $preset_information) {
					$preset_list[$preset_information->uuid] = $preset_information->preset_name;
				}
				
				$this->register_option( 'auphonic_production_preset', 'select', array(
				'label'       => __( 'Auphonic production preset', 'podlove' ),
				'description' => 'This preset will be used, if you create Auphonic production from an Episode.',
				'html'        => array( 'class' => 'regular-text' ),
				'options'	  => $preset_list
				) );
    		
    		}
    }

    public function admin_print_styles() {

    	wp_register_style(
    		'podlove_auphonic_admin_style',
    		$this->get_module_url() . '/admin.css',
    		false,
    		\Podlove\get_plugin_header( 'Version' )
    	);
    	wp_enqueue_style('podlove_auphonic_admin_style');

    	wp_register_script(
    		'podlove_auphonic_admin_script',
    		$this->get_module_url() . '/admin.js',
    		array( 'jquery', 'jquery-ui-tabs' ),
    		\Podlove\get_plugin_header( 'Version' )
    	);
    	wp_enqueue_script('podlove_auphonic_admin_script');
    }
    
    public function auphonic_episodes( $wrapper, $episode ) {
    	$wrapper->callback( 'import_from_auphonic_form', array(
			'label'    => __( 'Auphonic', 'podlove' ),
			'callback' => array( $this, 'auphonic_episodes_form' )
		) );			
    }

    public function auphonic_episodes_form() {
		$asset_assignments = Model\AssetAssignment::get_instance();
		?>

		<input type="hidden" id="auphonic" value="1"
			data-api-key="<?php echo $this->get_module_option('auphonic_api_key') ?>"
			data-assignment-chapter="<?php echo $asset_assignments->chapters ?>"
			data-assignment-image="<?php echo $asset_assignments->image ?>"
			data-module-url="<?php echo $this->get_module_url() ?>"
			/>

		<div id="auphonic-box">
			
			<ul>
				<li><a href="#auphonic-box-create">Create new Production</a></li>
				<li><a href="#auphonic-box-import">Import from Production</a></li>
			</ul>

			<div id="auphonic-box-create" class="tab-page">
				<label>
					<span>Service</span>
					<select>
						<option>Dropbox</option>
					</select>
				</label>

				<label>
					<span>Master Audio File</span> <i class="podlove-icon-repeat" title="fetch available audio files"></i>
					<select>
						<option>cre194-bier.mp3</option>
					</select>
				</label>

				<button class="button">
					<i class="podlove-icon-plus">
						&nbsp;Create new production from episode data
					</i>
				</button>
			</div>

			<div id="auphonic-box-import" class="tab-page">

				<label>
					<div class="auphonic_production_head">
						<span>Production</span>
						<span title="fetch available productions" id="reload_productions_button" data-token='<?php echo $this->get_module_option('auphonic_api_key') ?>'>
							<span class="state_idle"><i class="podlove-icon-repeat"></i></span>
							<span class="state_working"><i class="podlove-icon-spinner rotate"></i></span>
							<span class="state_success"><i class="podlove-icon-ok"></i></span>
						</span>
						<i id="open_production_button" class="podlove-icon-external-link" title="open in Auphonic"></i>
					</div>

					<div>
						<select name="import_from_auphonic" id="import_from_auphonic">
							<option><?php echo __( 'Loading productions ...', 'podlove' ) ?></option>
						</select>
					</div>
				</label>

				<div>
					<label>
						<input type="checkbox" id="force_import_from_auphonic" style="width: auto"> <?php echo __( 'Overwrite existing content', 'podlove' ) ?>
					</label>
				</div>

				<div style="clear: both"></div>

				<button id="fetch_production_data_button" class="button">
					<span>
						<span class="state_idle"><i class="podlove-icon-cloud-download"></i></span>
						<span class="state_working"><i class="podlove-icon-spinner rotate"></i></span>
						<span class="state_success"><i class="podlove-icon-ok"></i></span>
					</span>
					&nbsp;Import episode data from production
				</button>
			</div>

		</div>

		<?php
		/*
		<!-- YE OLDE STUFF BELOW 

		<div id="auphonic-import-form">
		
			<div id="create-auphonic-production-form" style="margin-bottom: 5px">
				<div class="auphonic-select-wrapper">
					<div class="auphonic-button-wrapper">
						<a class='button' id='create_auphonic_production_button' class='button' data-token='<?php echo $this->get_module_option('auphonic_api_key') ?>' data-presetuuid='<?php echo $this->get_module_option('auphonic_production_preset') ?>'>
							<i class="podlove-icon-plus">
								&nbsp;Create new production from episode data
							</i>
							<div>
								<span id="fetch_create_production_status"></span>
							</div>
						</a>
					</div>
					<span id="new_created_episode_data"></span>
				</div>
				<div style="clear: both"></div>
			</div>

			<div class="auphonic-select-wrapper">
				<label for="import_from_auphonic">Production</label>
				<select name="import_from_auphonic" id="import_from_auphonic">
					<option value="<?php echo $production_data->uuid ?>">
						<?php echo $displayed_name." (".date( "Y-m-d H:i:s", strtotime($production_data->creation_time)).") [".$production_data->status_string."]"; ?>
					</option>
				</select>
			</div>
			
			<div class="auphonic-button-wrapper" style="float: left; margin-right: 10px;">
				<a class='button' id='reload_productions_button' class='button' data-token='<?php echo $this->get_module_option('auphonic_api_key') ?>'>
					<i class="podlove-icon-repeat"></i> <span id="reload_episodes_status"></span>
				</a>
			</div>
			
			<div class="auphonic-button-wrapper" style="float: left; margin-right: 10px;">
				<a class='button' id='open_production_button' class='button' data-token='<?php echo $this->get_module_option('auphonic_api_key') ?>'>
					<i class="podlove-icon-external-link">
						&nbsp;Open in Auphonic
					</i>
				</a>
			</div>
			
			<div style="clear: both"></div>
			
			<div style="margin: 5px 0px 0px 0px;">
				<div class="auphonic-button-wrapper">
					<a class='button' id='fetch_production_data_button' class='button' data-token='<?php echo $this->get_module_option('auphonic_api_key') ?>'>
						<i class="podlove-icon-cloud-download">
							&nbsp;Import episode data from production
						</i>
						<div>
							<span id="fetch_production_status"></span>
						</div>
					</a>
				</div>

				<div class="auphonic-checkbox-wrapper">
					<input type='checkbox' id='force_import_from_auphonic'/>
					<label for='force_import_from_auphonic' title="<?php echo __( 'Overwrite all fields, even if they are already filled out.', 'podlove' ) ?>"><?php echo __( 'Overwrite existing content', 'podlove' ) ?></label>
				</div>
				<div style="clear: both"></div>
			</div>

			<div style="clear: both"></div>
		</div>
		-->
		
		<?php
		*/
    }
    
    public function check_code() { 
    	if( isset( $_GET["code"] ) && $_GET["code"] ) {
    		if($this->get_module_option('auphonic_api_key') == "") {
				$ch = curl_init('http://auth.podlove.org/auphonic.php');                                                                      
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");       
				curl_setopt($ch, CURLOPT_USERAGENT, \Podlove\Http\Curl::user_agent());                                                              
				curl_setopt($ch, CURLOPT_POSTFIELDS, array(  
					   "redirect_uri" => get_site_url().'/wp-admin/admin.php?page=podlove_settings_modules_handle',                                                                      
					   "code" => $_GET["code"]));                                                              
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);        
			
				$result = curl_exec($ch);
						
				$this->update_module_option('auphonic_api_key', $result);
				header('Location: '.get_site_url().'/wp-admin/admin.php?page=podlove_settings_modules_handle');
			}
    	}
    	
    	if ( isset( $_GET["reset_auphonic_auth_code"] ) && $_GET["reset_auphonic_auth_code"] == "1" ) {
    		$this->update_module_option('auphonic_api_key', "");
    		header('Location: '.get_site_url().'/wp-admin/admin.php?page=podlove_settings_modules_handle');
    	}
    	
    }
    
}