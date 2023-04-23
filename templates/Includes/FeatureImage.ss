<% if $hasFeatureImages() %>
	<div class="feature-image">
		<div class="container">
			<% if $FeaturedImageText %>
				<p class="sr-only">$FeaturedImageText</p>
			<% end_if %>
		</div>
	</div>
<% end_if %>