set -e
set -o pipefail
set -o verbose

folder=`pwd`/tools/
cd $folder


#download strelka2
wget https://github.com/Illumina/strelka/releases/download/v2.9.10/strelka-2.9.10.centos6_x86_64.tar.bz2
tar xjf strelka-2.9.10.centos6_x86_64.tar.bz2
rm strelka-2.9.10.centos6_x86_64.tar.bz2

#download mantis
cd $folder
wget https://github.com/OSU-SRLab/MANTIS/archive/v1.0.4.tar.gz
tar xzf v1.0.4.tar.gz
rm v1.0.4.tar.gz
