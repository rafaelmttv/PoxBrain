<nav class="navbar navbar-inverse navbar-fixed-top">
  <div class="container">
    <a class="navbar-brand" href="/">PoxBrain</a>
    <div id="search-form" class="">
      <form class="navbar-form" action="/search.php" method="get" onsubmit="return performSearch()" >
        <div id="search-group">
          <input type="text" name="search" id="search" placeholder="Search..." autocomplete="off" class="form-control">
          <input type="text" id="hard-search" disabled="disabled" class="form-control">
          <div id="search-items">
          </div>
        </div>
        <button id="search-submit" type="submit" class="btn btn-success">Go</button>
      </form>
    </div><!--/.navbar-collapse -->
  </div>
</nav>
<div id="ability-popup">
  
</div>
<div id="loading">
	<div id="loading-circles">
		<div class="loading-circle"></div>
		<div class="loading-circle2"></div>
	</div>
	<div id="loading-message">
		<span>We're finishing up some things. Please try to keep this tab open and not navigate away until it finishes. Once this finishes, you'll get suggested
		  search results as you type and hovering over abilities will tell you what they do</span>
	</div>
</div>