//select js
$(function() {
			$('.select2-ajax').select2({
			    placeholder: 'Buscar usuario...',
			    minimumInputLength: 2,
			    ajax: {
			        url: '/usuarios/buscar', // tu ruta Laravel
			        dataType: 'json',
			        delay: 250,
			        data: function (params) {
			            return {
			                q: params.term, // término de búsqueda
			                page: params.page || 1
			            };
			        },
			        processResults: function (data, params) {
			            params.page = params.page || 1;

			            return {
			                results: data.results,
			                pagination: {
			                    more: data.more
			                }
			            };
			        },
			        cache: true
			    }
			});
});
$(function() {
    $('.select-1').select2();
});
$(function() {
    $('.select-example').select2();
});
$(function() {
    $('.select-example-two').select2();
});
$(function() {
    $('.select-basic-multiple-four').select2();
});
$(".select-example-rtl").select2({
    dir: "rtl"
  });
$(".js-example-disabled").select2();
$(".select-basic-multiple-five").select2();
$(".select-basic-multiple-seven").on("click", function () {
  $(".js-example-disabled").prop("disabled", false);
  $(".select-basic-multiple-five").prop("disabled", false);
});
$(".select-basic-multiple-six").on("click", function () {
  $(".js-example-disabled").prop("disabled", true);
  $(".select-basic-multiple-five").prop("disabled", true);
});
$('.select2-icon').select2({
    width: "100%",
});
$('.select2-icons').select2({
    width: "100%",
});


