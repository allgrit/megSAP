<?php 
/** 
	@page vc_strelka2
*/

require_once(dirname($_SERVER['SCRIPT_FILENAME'])."/../Common/all.php");

error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);

//command line arguments
$parser = new ToolBase("vc_strelka2", "Call somatic variants with Strelka2.");
$parser->addInfile("t_bam",  "Tumor sample BAM file.", false);
$parser->addInfile("n_bam",  "Normal sample BAM file.", false);
$parser->addOutfile("out", "Output file in VCF format (gzipped and tabix indexed).", false);
//optional arguments
$parser->addString("smallIndels", "File for improved indel calling, generated by Manta.", true);
$parser->addInfile("target", "Enrichment target BED file.", true);
$parser->addString("build", "The genome build to use.", true, "GRCh37");
$parser->addFlag("wgs", "Treat input as WGS samples.");
$parser->addString("config", "Config file for strelka.", true, get_path('strelka2')."/configureStrelkaSomaticWorkflow.py.ini");
$parser->addFlag("keep_lq", "Keep variants flagged as low quality by Strelka2.");
$parser->addString("analysis_dir", "Keep analysis files in this directory.", true);
$parser->addString("debug_region", "Debug option to limit analysis to one region.", true);
$parser->addInt("threads", "Number of threads to use.", true, 4);
//extract arguments
extract($parser->parse($argv));

//################################################################################################
//Run Strelka2
//################################################################################################

//check config file
if (!is_file($config))
{
	trigger_error("Could not find strelka config file '" . $config . "'.", E_USER_ERROR);
}

//resolve run dir
if (isset($analysis_dir))
{
	$run_dir = $analysis_dir;
}
else
{
	$run_dir = $parser->tempFolder() . "/strelkaAnalysis";
}
$somatic_snvs = "$run_dir/results/variants/somatic.snvs.vcf.gz";
$somatic_indels = "$run_dir/results/variants/somatic.indels.vcf.gz";

//arguments
$args = [
	"--tumor ".realpath($t_bam),
	"--normal ".realpath($n_bam),
	"--referenceFasta ".genome_fasta($build),
	"--runDir ".$run_dir
];
if (!$wgs)
{
	$args[] = "--exome";
}
if (isset($smallIndels))
{
	$args[] = "--indelCandidates {$smallIndels}";
}

if (isset($debug_region))
{
	$args[] = "--region {$debug_region}";
}

//run
$parser->exec(get_path("strelka2")."/configureStrelkaSomaticWorkflow.py", implode(" ", $args), true);
$parser->exec("$run_dir/runWorkflow.py", "-m local -j $threads -g 4", false);

//################################################################################################
//Split multi-allelic variants
//################################################################################################

$split_snvs = "$run_dir/results/variants/somatic.snvs.split.vcf.gz";
$pipeline = [
		["zcat", $somatic_snvs],
		[get_path("vcflib")."vcfbreakmulti", "> $split_snvs"]
	];
$parser->execPipeline($pipeline, "splitting SNVs");

$split_indels = "$run_dir/results/variants/somatic.indels.split.vcf.gz";
$pipeline = [
		["zcat", $somatic_indels],
		[get_path("vcflib")."vcfbreakmulti", "> $split_indels"]
	];
$parser->execPipeline($pipeline, "splitting InDels");

//################################################################################################
//Merge SNV and INDELs into one VCF file
//################################################################################################

$strelka_snvs = Matrix::fromTSV($split_snvs);
$strelka_indels = Matrix::fromTSV($split_indels);
$merged = new Matrix();

//collect comments
$comments = array_unique(array_merge($strelka_snvs->getComments(), $strelka_indels->getComments()));

//remove duplicate DP (different description)
$comments = array_diff($comments, [
	'#FORMAT=<ID=DP,Number=1,Type=Integer,Description="Read depth for tier1 (used+filtered)">',
	'#INFO=<ID=QSI_NT,Number=1,Type=Integer,Description="Quality score reflecting the joint probability of a somatic variant and NT">'
	]);
$comments[] = '#INFO=<ID=QSI_NT,Number=1,Type=Integer,Description="Quality score reflecting the joint probability of a somatic variant (indels) and NT">';
$merged->setComments(sort_vcf_comments($comments));
$merged->setHeaders($strelka_snvs->getHeaders());

//add SNVs
for($i = 0; $i < $strelka_snvs->rows(); ++$i)
{
	$row = $strelka_snvs->getRow($i);
	$merged->addRow($row);
}

//add indels
for ($i=0; $i < $strelka_indels->rows(); ++$i)
{
	$row = $strelka_indels->getRow($i);

	//remove overfluent '.' at end or beginning of indels that can be found with long indels - bug?
	$row[3] = trim($row[3], ".");
	$row[4] = trim($row[4], ".");

	$merged->addRow($row);
}

//store
$vcf_merged = $parser->tempFile("_merged.vcf");
$merged->toTSV($vcf_merged);

//left-align
$vcf_aligned = $parser->tempFile("_aligned.vcf");
$parser->exec(get_path("ngs-bits")."VcfLeftNormalize", " -in $vcf_merged -out $vcf_aligned -ref ".genome_fasta($build), true);

//sort
$vcf_sorted = $parser->tempFile("_sorted.vcf");
$parser->exec(get_path("ngs-bits")."VcfSort","-in $vcf_aligned -out $vcf_sorted", true);

//################################################################################################
//Filter variants
//################################################################################################

$variants = Matrix::fromTSV($vcf_sorted);
$variants_filtered = new Matrix();

//fix column names
$colnames = $variants->getHeaders();
$colidx_tumor = array_search("TUMOR", $colnames);
$colidx_normal = array_search("NORMAL", $colnames);
$colnames[$colidx_tumor] = basename($t_bam, ".bam");
$colnames[$colidx_normal] = basename($n_bam, ".bam");

//quality cutoffs
$min_td = 20;
$min_taf = 0.05;
$min_tsupp = 3;
$min_nd = 20;
$max_naf_rel = 1/6;

//set comments and column names
$filter_format = '#FILTER=<ID=%s,Description="%s">';
$comments = [
	sprintf($filter_format, "all-unknown", "Allele unknown"),
	sprintf($filter_format, "special-chromosome", "Special chromosome"),
	sprintf($filter_format, "depth-tum", "Sequencing depth in tumor is too low (< {$min_td})"),
	sprintf($filter_format, "freq-tum", "Allele frequency in tumor < {$min_taf}"),
	sprintf($filter_format, "depth-nor", "Sequencing depth in normal is too low (< {$min_nd})"),
	sprintf($filter_format, "freq-nor", "Allele frequency in normal > ".number_format($max_naf_rel, 2)." * allele frequency in tumor"),
	sprintf($filter_format, "lt-3-reads", "Less than {$min_tsupp} supporting tumor reads")
	];

$variants_filtered->setComments(sort_vcf_comments(array_merge($variants->getComments(), $comments)));
$variants_filtered->setHeaders($colnames);

for($i = 0; $i < $variants->rows(); ++$i)
{
	$row = $variants->getRow($i);

	$ref = $row[3];
	$alt = $row[4];
	$format = $row[8];
	$tumor = $row[$colidx_tumor];
	$normal = $row[$colidx_normal];

	$filters = [];
	$type = (strlen($row[3]) > 1 || strlen($row[4]) > 1) ? "INDEL" : "SNV";

	if (!$keep_lq && $row[6] !== "PASS")
	{
		continue;
	}

	$filter = array_diff(explode(";", $row[6]), ["PASS"]);

	if (!preg_match("/^[acgtACGT]*$/", $alt))
	{
		$filter[] = "all-unknown";
	}
	if (chr_check($row[0], 22, false) === FALSE)
	{
		$filter[] = "special-chromosome";
	}
	$calls = [];
	if ($type === "SNV" && preg_match("/^[acgtACGT]*$/", $alt))
	{
		list($td, $tf) = vcf_strelka_snv($format, $tumor, $alt);
		list($nd, $nf) = vcf_strelka_snv($format, $normal, $alt);
		$calls[] = [ $alt, $td, $tf, $nd, $nf, $filter ];

		//add post-call variants
		$postcalls = vcf_strelka_snv_postcall($format, $tumor, $ref, $alt, $min_taf);
		foreach ($postcalls as $pc)
		{
			$calls[] = [ $pc[0], $td, $pc[1], $nd, $nf, $filter ];
		}
	}
	else if ($type === "INDEL")
	{
		list($td, $tf) = vcf_strelka_indel($format, $tumor);
		list($nd, $nf) = vcf_strelka_indel($format, $normal);
		$calls[] = [ $alt, $td, $tf, $nd, $nf, $filter ];
	}

	foreach ($calls as $call)
	{
		$variant = $row;
		list($alt, $td, $tf, $nd, $nf, $filter) = $call;
		$variant[4] = $alt;

		if ($td * $tf < $min_tsupp) $filter[] = "lt-3-reads";
		if ($td < $min_td) $filter[] = "depth-tum";
		if ($nd < $min_nd) $filter[] = "depth-nor";
		if ($tf < $min_taf) $filter[] = "freq-tum";
		if ($nf > $max_naf_rel * $tf) $filter[] = "freq-nor";

		if (empty($filter))
		{
			$filter[] = "PASS";
		}
		$variant[6] = implode(";", $filter);

		$variants_filtered->addRow($variant);
	}
}
$vcf_filtered = $parser->tempFile("_filtered.vcf");
$variants_filtered->toTSV($vcf_filtered);
$final = $vcf_filtered;

//flag off-target variants
if (!empty($target))
{
	$vcf_offtarget = $parser->tempFile("_filtered.vcf");
	$parser->exec(get_path("ngs-bits")."VariantFilterRegions", "-in $vcf_filtered -mark off-target -reg $target -out $vcf_offtarget", true);
	$final = $vcf_offtarget;
}

//zip and index output file
$parser->exec("bgzip", "-c $final > $out", true);
$parser->exec("tabix", "-p vcf $out", true);

?>