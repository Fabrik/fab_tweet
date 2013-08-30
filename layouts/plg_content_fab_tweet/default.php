<?php
/**
 * Fab Tweet - Twitter user time line module
 *
 * @package     Joomla.Site
 * @subpackage  Module.FabTweet
 * @since       2.5
 */

// No direct access
defined('_JEXEC') or die('Restricted access');

?>
<ul class="fab_tweets">
<?php
foreach ($displayData as $item) :
	$user = $item->user;

?>
	<li>
		<a class="image" href="http://twitter.com/<?php echo $user->screen_name?>">
			<img src="<?php echo $user->profile_image_url; ?>" alt="<?php echo $user->name?>" />
		</a>
		<p class="tweet">
		<?php echo $item->text?>
		</p>
		<small>
			By <a href="http://twitter.com/<?php echo $user->screen_name?>">
				<?php echo $user->name?>
				</a>
				<time class="timeago" datetime="<?php echo $item->created_at?>"><?php echo $item->created_at?></time>

		</small>
	</li>
<?php
endforeach;
?>
</ul>