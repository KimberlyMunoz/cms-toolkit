<?php
/**
* Metaboxes.php creates a custom metabox for the regulations taxonomy. Needs more
* modularity.
* 
**/
// On 'add_meta_boxes', rip out the old metaboxes and replace them with regulation_meta_box() (below).
namespace CFPB\Utils\MetaBox;
use \CFPB\Utils\Taxonomy as TaxUtils;
use \CFPB\Utils\MetaBox\View;
use \CFPB\Utils\MetaBox\Callbacks;
use \WP_Error as WP_Error;
use \DateTime;

class Models {
    public $title;
    public $slug;
    public $post_type;
    public $context;
    public $fields;
    public $priority;
    public $Callbacks; // obj A class containing other validation methods
    public $View; // obj A class containing template patterns
    public $error;
    private $selects = array(
        'select',
        'multiselect',
        'taxonomyselect',
        'tax_as_meta',
        'post_select',
        'post_multiselect'
    );
    private $inputs  = array(
        'text_area',
        'number',
        'text',
        'boolean',
        'email',
        'url',
        'date',
        'radio',
        'link',
    );
    private $hidden  = array( 'nonce', 'hidden' );
    private $other   = array( 'separator' );
    /**
    *
    * Create a meta box based on a few parameters.
    *
    * This function allows developers with this plugin installed to easily
    * instantiate meta boxes into different edit screens of their WordPress
    * install. Inspired by Django forms. The generate() method parses parameters
    * into an objeect, the last property of which is passed to the build()
    * callback. Generating metaboxes is as simple as hooking this method into
    * the `add_meta_boxes` action hook.
    *
    * @since 1.0
    *
    * @uses wp_parse_args to determine the desired differences from defaults
    * @uses \CFPB\Utils\MetaBox\Template\HTML(); for generating form fields
    * @uses add_meta_box (WP Core) to instantiate the meta box
    *
    * @param str       $title the title as the user wants it displayed
    * @param str       $slug the title as it should be represented in code
    * @param str/array $for the post types that should get this meta box
    * @param str        $part the section on the screen where the box should be
    * @param array  $fields an array of html form fields the box contains
    *
    * All parameters are required
    *
    **/
    public function __construct() {
        $this->Callbacks = new Callbacks();
        $this->View      = new View();
        $this->priority  = 'default';
        if ( ! is_array($this->post_type) ) {
            $this->post_type = array($this->post_type);
        }
        $this->error = '\WP_Error';
    }

    public function set_callbacks( $Class ) {
        $this->Callbacks = $Class;
    }

    public function set_view( $view ) {
        $this->View = $view;
    }

    public function error_handler( $Class ) {
        $this->error = $Class;
    }

    public function check_post_type( $post_type ) {
        if ( post_type_exists( $post_type ) ) {
            $post_type = sanitize_key( $post_type );
        } else {
            $post_type = false;
        }
        return $post_type;
    }

    public function generate( ) {

        $parts = array( 'normal', 'advanced', 'side', );
        if ( ! in_array( $this->context, $parts ) ) {
            $error = new $this->error( 'context', __( 'Invalid context: ' . $this->context ) );
            echo $error->get_error_message('context');
            return;
        }

        $fields = $this->fields;
        $post_types = $this->post_type;
        foreach ( $post_types as $p ) {
            $exists = $this->check_post_type($p);
            if ( $exists != false ) {
                add_meta_box(
                $id = $this->slug,
                $title = $this->title,
                $callback = array( $this->View, 'ready_and_print_html' ),
                $post_type = $p,
                $context = $this->context,
                $priority = $this->priority,
                $callback_args = $fields
            );
        } else {
            $error = new $this->error( 'post_type', 'Invalid post type: ' . $p);
            echo $error->get_error_message('post_type');
        }
    }
}

public function validate_link( $field, $post_id ) {
    $key = $field['meta_key'];
    if ( array_key_exists('count', $field['params'] ) ) {
        $count = $field['params']['count'] - 1;
    } else {
        $count = 1;
    }
    for ( $i = 0; $i <= $count; $i++ ) {
        if ( empty( $_POST[$key . '_url_' . $i] ) || empty( $_POST[$key.'_text_' . $i]) ) {
            return;
        }
        $url = $_POST["{$key}_url_{$i}"];
        $text = $_POST["{$key}_text_{$i}"];
        $full_link = array( 0 => $url, 1 => $text );
        $meta_key = $key . "_{$i}";
        $existing = get_post_meta( $post_id, $meta_key, $single = false );
        if ( empty($existing) ) {
            add_post_meta( $post_id, $meta_key, $url, false );
            add_post_meta( $post_id, $meta_key, $text, false );
        } elseif ( $existing != $full_link ) {
            update_post_meta( $post_id, $meta_key, $url, $existing[0] );
            update_post_meta( $post_id, $meta_key, $text, $existing[1] );
        }

        $meta_key = $key;
    }
}

public function validate_select( $field, $post_id ) {
    $key = $field['meta_key'];
    $existing = get_post_meta( $post_id, $key, false );
    $data = $_POST[$key];
    if ( array_key_exists($key, $_POST) ) {
        foreach ( $data as $d ) {
            // Adding or updating terms
            $term = sanitize_text_field( $d );
            $e_key = array_search($term, $existing);
            if ( ! in_array($d, (array)$existing) ) {
                // if the term is not in $existing, it's a new term, add it
                // we use add_post_meta instead of update so we can have more
                // than one value on the array
                add_post_meta( $post_id, $key, $term );
            }
        }
        // delete terms if they're not in the $_POST data
        foreach ( (array)$existing as $e ) {
            if ( ! in_array($e, $data) ) {
                delete_post_meta( $post_id, $key, $meta_value = $e );
            }
        }
    }  else {
        if ( ! empty($existing) ) {
            delete_post_meta( $post_id, $key );
            // if there's no $_POST data but the post has meta data
            // it means someone removed the term from the multiselect
            // and we should delete the metadata. 
        }
    }
}

public function validate_taxonomyselect($field, $post_id) {
    $key = $field['slug'];
    if ( isset($_POST[$key] )) {
        $term = sanitize_text_field( $_POST[$key] );
        $term_exists = get_term_by('id', $term, $field['taxonomy']);
        if ( $term_exists ){
            wp_set_object_terms(
            $post_id,
            $term_exists->name,
            $field['taxonomy'],
            $append = $field['multiple']
        );
    } else {
        wp_set_object_terms(
        $post_id,
        $term,
        $field['taxonomy'],
        $append = $field['multiple']
    );
    }
}
}

public function validate_date($field, $post_id) {
    $year = $field['taxonomy'] . '_year';
    $month = $field['taxonomy'] . '_month';
    $day = $field['taxonomy'] . '_day';
    $data = array($field['taxonomy'] => '');
    if ( isset($_POST[$month]) ) {
        $data[$field['taxonomy']] = $_POST[$month];
    }
    if ( isset( $_POST[$day] ) ) {
        $data[$field['taxonomy']] .= ' ' . $_POST[$day];
    }
    if ( isset( $_POST[$year] ) ) {
        $data[$field['taxonomy']] .= ' ' . $_POST[$year];
    }
    $date = DateTime::createFromFormat('F j Y', $data[$field['taxonomy']]);
    if ( $date ) {
        $this->Callbacks->date( $post_id, $field['taxonomy'], $multiples = $field['multiples'], $data );
    }
}

/**
 * checks that data is coming through in the types we expect or ferries out form 
 * data to the appropriate validator. Run before save to ensure you're saving
 * correct data. Essentially this takes in $_POST and pushes all the good stuff from
 * $_POST into a separate, cleaned array and returns the cleaned data.
 * 
 * @return mixed either returns cleaned post values or errors if data is invalid
 */
public function validate( $post_ID ) {
    $data = array_intersect_key($_POST, $this->fields);
    $postvalues = array();
    foreach ( $this->fields as $field ) {
        if ( array_key_exists('do_not_validate', $field) ) {
            return;
        }
        /* if this field is a taxonomy select, date, link or select field, we
           send it out to another validator
        */
        if ( $field['type'] == 'taxonomyselect') {
            $this->validate_taxonomyselect( $field, $post_ID );
        } elseif ( in_array( $field['type'], $this->selects ) ) {
            $this->validate_select( $field, $post_ID );
        } elseif ( $field['type'] === 'date' ) {
            $this->validate_date( $field, $post_ID );
        } elseif ( $field['type'] === 'link' ) {
            $this->validate_link($field, $post_ID);
        } else {
            /* 
                For most field types we just need to make sure we have the data
                we expect from the form and sanitize them before sending them to
                save
            */
            $key = $field['slug'];
            if ( isset( $_POST[$key] ) ) {
                if ( $field['type'] === 'number' ) {
                    if ( is_numeric( $data[$key] ) ) {
                        $postvalues[$key] = intval( $data[$key] ); // if we're expecting a number, make sure we get a number
                    }
                } elseif ( $field['type'] === 'url' ) {
                    $postvalues[$key] = esc_url_raw( $data[$key] ); // if we're expecting a url, make sure we get a url
                } elseif ( $field['type'] === 'email' ) {
                    $postvalues[$key] = sanitize_email( $data[$key] ); // if we're expecting an email, make sure we get an email
                } elseif ( ! empty( $data[$key] ) && ! is_array($data[$key])) {
                    $postvalues[$key] = (string)$data[$key]; // make sure whatever we get for anything else is a string
                }
            }
        }
    }
    return $postvalues;
}

public function save( $post_ID, $postvalues ) {
    global $post;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) // Do nothing if we're auto saving
    return;

    if ( empty( $postvalues ) ) {
        return;
    }
    // save post data for any fields that sent them
    foreach ( $postvalues as $key => $value ) {
        update_post_meta( $post_ID, $meta_key = $key, $meta_value = $value );
    }
}

public function validate_and_save( $post_ID ) {
    $validate = $this->validate( $post_ID );
    $type = gettype($validate);
    $count = count($validate);
    $this->save( $post_ID, $validate );
}
/**
* Moved premanently: This function is now located in the \CFPB\Utils\MetaBox\Template namespace, in the HTML class.
*
* @since v1.0
*
**/
public function date_meta_box( $taxonomy, $tax_nice_name, $mutliples = false ) {
    $error = new $this->error( 'moved', __( 'This function has moved to the \CFPB\Utils\MetaBox\HTML namespace. Look for it there as simply date()!' ) );
    echo $error->get_error_message('moved');
}
}
